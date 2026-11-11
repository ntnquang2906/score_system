"""
Đọc 1 tệp minh chứng và trả về DỮ KIỆN, không kết luận đạt/không đạt (việc đó do backend làm
theo quy tắc của từng tiêu chí, xem backend/app/Domain/Evidence):
  - chữ trong tệp: PDF có lớp chữ thì đọc thẳng, ảnh/bản scan thì OCR tiếng Việt
  - con dấu: vùng màu đỏ hình tròn
  - chữ ký: chữ ký số trong PDF, nét mực xanh, hoặc nét mực trong khoảng trống giữa
    chức danh và họ tên người ký
  - số dòng dữ liệu (Excel)
Không giới hạn định dạng: PDF, ảnh (kể cả HEIC/AVIF, TIFF nhiều trang), Word/Excel/PowerPoint mọi đời
và OpenDocument (qua LibreOffice), tệp nén (zip, rar, 7z, tar...: đọc từng tệp bên trong), tệp chữ.
Định dạng lạ thì thử mở bằng LibreOffice trước khi báo chưa hỗ trợ.
Chạy trên CPU. Có GPU: đặt VLM_URL (Ollama, vd http://vlm:11434) để thêm nhận định của mô hình thị giác - ngôn ngữ
(đọc ảnh trang đầu + trang có dấu/chữ ký cùng nội dung tiêu chí) vào trường "vlm"; backend ưu tiên dùng khi có.
"""
import base64
import io
import json
import os
import re
import shutil
import subprocess
import tempfile
import time
import unicodedata
import urllib.request
import zipfile

import cv2
import fitz
import numpy as np
import pytesseract
from fastapi import FastAPI, File, Form, UploadFile
from PIL import Image, ImageSequence
from pillow_heif import register_heif_opener

register_heif_opener()   # PIL mở được HEIC/HEIF (ảnh iPhone), AVIF

ENGINE = 'cpu-1'
MAX_TEXT = 40_000
MAX_PAGES = 40                                         # số trang tối đa đọc lớp chữ
MAX_OCR_PAGES = int(os.getenv('MAX_OCR_PAGES', '8'))   # OCR tốn thời gian: chỉ OCR các trang đầu/cuối
OCR_LANG = 'vie+eng'
IMAGE_EXT = {'jpg', 'jpeg', 'jfif', 'png', 'webp', 'bmp', 'gif', 'tif', 'tiff', 'heic', 'heif', 'avif'}
SHEET_EXT = {'xls', 'xlsb', 'ods', 'fods', 'numbers'}   # bảng tính đời cũ: đổi sang xlsx để đếm dòng
ARCHIVE_EXT = {'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'}
TEXT_EXT = {'txt', 'csv', 'tsv', 'md', 'html', 'htm', 'xml', 'json'}
MAX_ARCHIVE_FILES = 30                 # số tệp tối đa đọc trong 1 tệp nén
MAX_ARCHIVE_BYTES = 300 * 1024 * 1024  # tổng dung lượng giải nén tối đa (chống zip bomb)

# Mô hình thị giác - ngôn ngữ (tuỳ chọn, cần GPU). Không đặt VLM_URL => chỉ dùng OCR + xử lý ảnh như trên.
VLM_URL = os.getenv('VLM_URL', '').rstrip('/')
VLM_MODEL = os.getenv('VLM_MODEL', 'qwen2.5vl:7b')
VLM_TIMEOUT = int(os.getenv('VLM_TIMEOUT', '300'))
VLM_IMAGE_SIZE = int(os.getenv('VLM_IMAGE_SIZE', '1280'))   # cạnh dài ảnh gửi mô hình; chạy CPU nên giảm (vd 768) cho nhanh
VLM_TEXT_CHARS = int(os.getenv('VLM_TEXT_CHARS', '3000'))   # số ký tự chữ OCR gửi kèm
# Bước 1 (đọc ảnh): dữ kiện của văn bản. Bước 2 (chỉ chữ): trích yếu có trực tiếp chứng minh tiêu chí không.
# Tách 2 bước vì mô hình 7B đọc tốt nhưng gộp "đọc + phán đoán" trong 1 câu hỏi thì hay kết luận dễ dãi.
VLM_READ_SCHEMA = {
    'type': 'object',
    'properties': {
        'doc_type': {'type': 'string'},                                   # loại văn bản (Quyết định, Biên bản ghi nhớ...)
        'subject': {'type': 'string'},                                    # trích yếu / nội dung chính
        'issuer': {'type': 'string'},                                     # cơ quan/đơn vị ban hành
        'mentions_unit': {'type': 'boolean'},                             # có nhắc tới đơn vị được đánh giá
        'year': {'type': ['integer', 'null']},                            # năm ban hành
        'stamp': {'type': 'boolean'},
        'signature': {'type': 'boolean'},
    },
    'required': ['doc_type', 'subject', 'issuer', 'mentions_unit', 'year', 'stamp', 'signature'],
}
VLM_JUDGE_SCHEMA = {
    'type': 'object',
    'properties': {'relevant': {'type': 'string', 'enum': ['yes', 'no', 'unclear']}, 'reason': {'type': 'string'}},
    'required': ['relevant', 'reason'],
}

# Dòng chức danh người ký (đã bỏ dấu, viết hoa)
SIGNER_TITLE = re.compile(
    r'\b(HIEU TRUONG|VIEN TRUONG|GIAM DOC|CHU TICH|TRUONG KHOA|TRUONG PHONG|TRUONG BAN|CHU NHIEM|'
    r'THU TRUONG|TONG GIAM DOC|KT\.?|TM\.?|DAI DIEN|NGUOI LAP|NGUOI DE NGHI|BEN [AB]|'
    r'RECTOR|DIRECTOR|PRESIDENT|DEAN|REPRESENTATIVE)\b'
)

app = FastAPI()


@app.get('/health')
def health():
    return {'ok': True, 'engine': ENGINE, 'vlm': VLM_MODEL if VLM_URL else None}


@app.post('/analyze')
def analyze(file: UploadFile = File(...), context: str | None = Form(None)):
    """context (JSON, tuỳ chọn): {criterion, documents, keywords, unit} - để mô hình biết cần kiểm chứng điều gì"""
    started = time.monotonic()
    out = read_any(file.file.read(), file.filename or '')
    images = out.pop('_images', [])
    out['engine'] = ENGINE
    if VLM_URL and context and images:
        try:
            out['vlm'] = ask_vlm(images, out['text'], json.loads(context))
        except Exception as e:   # mô hình lỗi/quá tải: vẫn trả dữ kiện OCR như bình thường
            out['warnings'].append('vlm_failed')
            out['vlm_error'] = e.__class__.__name__
    out['duration_ms'] = int((time.monotonic() - started) * 1000)
    return out


def ask_vlm(images, text, context):
    """Mô hình đọc tối đa 2 ảnh (trang đầu, trang cuối/có dấu) lấy dữ kiện, rồi phán đoán riêng trích yếu so với tiêu chí"""
    picked = [images[0][1]] + ([images[-1][1]] if len(images) > 1 else [])
    facts = chat(VLM_READ_SCHEMA, [jpeg_base64(img) for img in picked], (
        'Ảnh là trang đầu và trang cuối (thường có chữ ký, con dấu) của một văn bản minh chứng. '
        f'Đơn vị được đánh giá: {context.get("unit", "")}\n'
        'Đoạn chữ đọc được từ văn bản (có thể sai chính tả do OCR):\n'
        f'"""{text[:VLM_TEXT_CHARS]}"""\n'
        'Hãy cho biết: doc_type (loại văn bản); subject (trích yếu, tức nội dung chính, thường ở dòng "Về việc..." ngay dưới tên '
        'loại văn bản, 1 câu); issuer (cơ quan ban hành); mentions_unit (có nhắc tới đơn vị được đánh giá không); year (năm ban hành); '
        'stamp (có con dấu đỏ không); signature (có chữ ký không). Chỉ trả lời JSON, bằng tiếng Việt.'
    ))
    judge = chat(VLM_JUDGE_SCHEMA, [], (
        f'Tiêu chí đánh giá: {context.get("criterion", "")}\n'
        f'Loại văn bản được chấp nhận: {", ".join(context.get("documents") or []) or "bất kỳ"}\n'
        f'Minh chứng nộp: {facts.get("doc_type", "")} "{facts.get("subject", "")}"\n'
        'Minh chứng này có TRỰC TIẾP chứng minh tiêu chí không? Chỉ xét nội dung chính (trích yếu) của văn bản, không suy diễn. '
        'Ví dụ: tiêu chí về hợp tác cần văn bản về hợp tác (thoả thuận, biên bản ghi nhớ, hợp đồng hợp tác); văn bản về chủ đề khác '
        '(hỗ trợ kinh phí, khen thưởng, tài chính...) là "no". Không đủ thông tin thì "unclear". Trả lời JSON bằng tiếng Việt.'
    ))
    return {'model': VLM_MODEL, **facts, **judge}


def chat(schema, images, prompt):
    """Gọi Ollama /api/chat, trả JSON theo schema"""
    message = {'role': 'user', 'content': prompt, **({'images': images} if images else {})}
    body = {'model': VLM_MODEL, 'stream': False, 'format': schema, 'options': {'temperature': 0}, 'messages': [message]}
    request = urllib.request.Request(f'{VLM_URL}/api/chat', data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(request, timeout=VLM_TIMEOUT) as response:
        answer = json.loads(json.loads(response.read())['message']['content'])
    return {k: answer.get(k) for k in schema['properties']}


def jpeg_base64(img):
    ok, buf = cv2.imencode('.jpg', fit(img, VLM_IMAGE_SIZE), [cv2.IMWRITE_JPEG_QUALITY, 85])
    return base64.b64encode(buf.tobytes()).decode()


def read_any(data, name, depth=0):
    ext = name.rsplit('.', 1)[-1].lower() if '.' in name else ''
    try:
        if data[:4] == b'%PDF':
            out = read_pdf(data)
        elif ext in IMAGE_EXT:
            out = read_image(data)
        elif ext in ('docx', 'docm'):
            out = read_docx(data)
        elif ext in ('xlsx', 'xlsm'):
            out = read_xlsx(data)
        elif ext in SHEET_EXT:
            out = read_xlsx(convert(data, ext, 'xlsx'))
        elif ext in ARCHIVE_EXT or is_archive(data) and ext not in ('docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'):
            out = read_archive(data, name, depth) if depth < 2 else result('unsupported', warnings=['nested_archive'])
        elif ext in TEXT_EXT:
            out = read_text(data, ext)
        elif looks_like_document(data, ext):
            # Word/PowerPoint đời cũ, OpenDocument, RTF, tệp chữ không rõ đuôi...: LibreOffice; không được thì thử như ảnh
            out = read_office(data, ext) or read_image(data)
        else:
            out = read_image(data)   # dữ liệu nhị phân lạ: chỉ thử như ảnh, không đọc thành chữ rác
    except Exception as e:  # tệp hỏng, có mật khẩu...
        out = result('unsupported', warnings=['cannot_open'], error=e.__class__.__name__)

    out['text'] = out['text'][:MAX_TEXT]
    out['readable'] = len(re.sub(r'\s+', '', out['text'])) >= 30
    return out


def result(kind, text='', **extra):
    return {'kind': kind, 'pages': 1, 'ocr_pages': [], 'text': text, 'stamps': [], 'signatures': [],
            'signature_blocks': [], 'rows': None, 'warnings': [], **extra}


# ------------------------------------------------------------------ đọc theo loại tệp

def read_pdf(data):
    doc = fitz.open(stream=data, filetype='pdf')
    n = doc.page_count
    out = result('pdf', pages=n)

    ocr_candidates = set(list(range(min(n, MAX_OCR_PAGES // 2))) + list(range(max(0, n - MAX_OCR_PAGES // 2), n)))
    texts = []
    for i in range(min(n, MAX_PAGES)):
        text = doc[i].get_text()
        # Trang scan (không có lớp chữ) hoặc lớp chữ hỏng (lỗi phông, OCR rác của máy scan) => tự OCR lại
        if (len(text.strip()) < 40 or garbled(text)) and i in ocr_candidates:
            ocr = ocr_text(render(doc[i], 250, gray=True))
            if len(text.strip()) < 40 or not garbled(ocr):
                text = ocr
                out['ocr_pages'].append(i + 1)
        if garbled(text) and 'garbled_text' not in out['warnings']:
            out['warnings'].append('garbled_text')
        texts.append(text)
    if n > MAX_PAGES:
        out['warnings'].append('too_many_pages')
    out['text'] = '\f'.join(texts)   # \f ngăn cách trang để backend biết trích dẫn nằm ở trang nào

    if doc.get_sigflags() > 0 or any(w.field_type == fitz.PDF_WIDGET_TYPE_SIGNATURE for p in doc for w in (p.widgets() or [])):
        out['signatures'].append({'page': None, 'method': 'digital'})

    # Dấu và chữ ký thường ở trang cuối; xem thêm trang áp cuối và trang đầu
    for i in sorted({0, n - 2, n - 1} & set(range(n))):
        page = doc[i]
        img = render(page, 120)
        scale = 120 / 72
        lines = pdf_lines(page, scale) if i + 1 not in out['ocr_pages'] else ocr_lines(img)
        inspect_page(out, img, lines, i + 1)
    return out


def read_image(data):
    """Ảnh thường đọc bằng OpenCV; HEIC/AVIF/GIF và TIFF nhiều trang (máy scan) đọc bằng PIL"""
    img = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)
    frames = [img] if img is not None else []
    if not frames or data[:4] in (b'II*\x00', b'MM\x00*'):
        try:
            pil = Image.open(io.BytesIO(data))
            frames = []
            for f in ImageSequence.Iterator(pil):   # phải đổi từng trang ngay khi duyệt (PIL dùng chung 1 đối tượng)
                frames.append(cv2.cvtColor(np.array(f.convert('RGB')), cv2.COLOR_RGB2BGR))
                if len(frames) >= MAX_OCR_PAGES:
                    break
        except Exception:
            pass
    if not frames:
        return result('unsupported', warnings=['unsupported_format'])

    out = result('image', pages=len(frames))
    texts = []
    for i, frame in enumerate(frames):
        frame = fit(frame, 2600)
        lines = ocr_lines(frame)
        texts.append('\n'.join(l['text'] for l in lines))
        out['ocr_pages'].append(i + 1)
        if i in (0, len(frames) - 1):
            inspect_page(out, frame, lines, i + 1)
    out['text'] = '\f'.join(texts)
    return out


OFFICE_MAGIC = (b'\xd0\xcf\x11\xe0', b'{\\rtf', b'PK\x03\x04', b'<?xml')   # Word/Excel/PPT đời cũ (OLE2), RTF, OpenDocument/OOXML, XML


def is_archive(data):
    return data[:4] == b'PK\x03\x04' or data[:6] == b'7z\xbc\xaf\x27\x1c' or data[:4] == b'Rar!' \
        or data[:2] in (b'\x1f\x8b', b'BZ') or data[257:262] == b'ustar'


def looks_like_document(data, ext):
    """Định dạng văn phòng đã biết, hoặc tệp chữ (ít ký tự điều khiển); dữ liệu nhị phân lạ thì không"""
    if ext in ('doc', 'dot', 'ppt', 'pps', 'pptx', 'ppsx', 'odt', 'odp', 'odg', 'rtf', 'wpd', 'wps', 'pages', 'key', 'hwp') \
            or data.startswith(OFFICE_MAGIC):
        return True
    head = data[:4096]
    control = sum(b < 9 or 13 < b < 32 for b in head)
    return bool(head) and control / len(head) < 0.02


def read_office(data, ext):
    """Word/PowerPoint đời cũ, OpenDocument, RTF...: LibreOffice đổi sang PDF rồi đọc như PDF (giữ cả ảnh scan nhúng trong tệp)"""
    try:
        out = read_pdf(convert(data, ext, 'pdf'))
    except Exception:
        return None
    out['kind'] = 'office'
    out['warnings'].append('editable_document')   # tệp sửa được, không phải bản đã ký
    return out


def convert(data, ext, target):
    with tempfile.TemporaryDirectory() as tmp:
        src = os.path.join(tmp, f'input.{ext or "bin"}')
        with open(src, 'wb') as f:
            f.write(data)
        subprocess.run(['soffice', '--headless', '--norestore', f'-env:UserInstallation=file://{tmp}/profile',
                        '--convert-to', target, '--outdir', tmp, src],
                       check=True, timeout=180, capture_output=True, env={**os.environ, 'HOME': tmp})
        with open(os.path.join(tmp, f'input.{target}'), 'rb') as f:
            return f.read()


def read_archive(data, name, depth):
    """Tệp nén: đọc từng tệp bên trong rồi gộp dữ kiện (dấu/chữ ký ghi kèm tên tệp)"""
    with tempfile.TemporaryDirectory() as tmp:
        suffix = re.search(r'(\.tar)?\.[A-Za-z0-9]+$', name)   # giữ đuôi kép .tar.gz để giải nén hết các lớp
        src = os.path.join(tmp, 'archive' + (suffix.group(0).lower() if suffix else '.zip'))
        with open(src, 'wb') as f:
            f.write(data)
        dest = os.path.join(tmp, 'x')
        if zipfile.is_zipfile(src):
            with zipfile.ZipFile(src) as z:
                if sum(i.file_size for i in z.infolist()) > MAX_ARCHIVE_BYTES:
                    return result('archive', warnings=['archive_too_large'])
                for info in z.infolist():   # tên tiếng Việt trong zip tạo trên Windows thường mã hoá cp437
                    if not info.flag_bits & 0x800:
                        try:
                            info.filename = info.filename.encode('cp437').decode('utf-8')
                        except UnicodeError:
                            pass
                    z.extract(info, dest)
        else:
            subprocess.run(['unar', '-q', '-f', '-D', '-o', dest, src], check=True, timeout=120, capture_output=True)

        files = []
        for root, _, names in os.walk(dest):
            for n in names:
                path = os.path.join(root, n)
                if not n.startswith('.') and '__MACOSX' not in path and not os.path.islink(path):
                    files.append(path)
        files.sort()
        if sum(os.path.getsize(f) for f in files) > MAX_ARCHIVE_BYTES:
            return result('archive', warnings=['archive_too_large'])

        out = result('archive', pages=0, files=[])
        texts, rows = [], None
        inner = [read_any(open(f, 'rb').read(), os.path.basename(f), depth + 1) | {'name': os.path.relpath(f, dest)}
                 for f in files[:MAX_ARCHIVE_FILES]]
        if len(files) > MAX_ARCHIVE_FILES:
            out['warnings'].append('too_many_files')
        for r in inner:
            out.setdefault('_images', []).extend(r.pop('_images', []))
            out['files'].append({'name': r['name'], 'kind': r['kind'], 'readable': r['readable']})
            out['pages'] += r['pages']
            texts.append(f"[{r['name']}]\n{r['text']}")
            for key in ('stamps', 'signatures', 'signature_blocks'):
                out[key] += [{**x, 'file': r['name']} for x in r[key]]
            out['ocr_pages'] += r['ocr_pages']
            if r['rows'] is not None:
                rows = (rows or 0) + r['rows']
        out['text'] = '\f'.join(texts)
        out['rows'] = rows
        # Cảnh báo chỉ giữ khi đúng với mọi tệp đọc được bên trong (vd: tất cả đều là Word => chưa có bản đã ký)
        readable = [r for r in inner if r['readable']]
        for w in ('editable_document', 'no_color'):
            if readable and all(w in r['warnings'] for r in readable):
                out['warnings'].append(w)
        if not inner:
            out['warnings'].append('empty_archive')
        return out


def read_text(data, ext):
    for enc in ('utf-8', 'utf-16', 'cp1258', 'latin-1'):
        try:
            text = data.decode(enc)
            break
        except UnicodeError:
            continue
    if ext in ('html', 'htm', 'xml'):
        text = re.sub(r'<[^>]+>', ' ', re.sub(r'(?is)<(script|style).*?</\1>', ' ', text))
    return result('text', text, warnings=['editable_document'])


def read_docx(data):
    import docx
    d = docx.Document(io.BytesIO(data))
    parts = [p.text for p in d.paragraphs]
    for table in d.tables:
        for row in table.rows:
            parts.append(' | '.join(c.text.strip() for c in row.cells))
    # File Word sửa được: không phải bản đã ký/đóng dấu
    return result('docx', '\n'.join(parts), warnings=['editable_document'])


def read_xlsx(data):
    import openpyxl
    wb = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
    lines, rows = [], 0
    for ws in wb.worksheets:
        filled = 0
        for values in ws.iter_rows(values_only=True):
            cells = [str(v).strip() for v in values if v is not None and str(v).strip()]
            if cells:
                filled += 1
                if sum(len(l) for l in lines) < MAX_TEXT:
                    lines.append(' | '.join(cells))
        rows += max(0, filled - 1)   # trừ dòng tiêu đề
    return result('xlsx', '\n'.join(lines), rows=rows, pages=len(wb.worksheets), warnings=['editable_document'])


# ------------------------------------------------------------------ ảnh trang

def render(page, dpi, gray=False):
    pix = page.get_pixmap(dpi=dpi, colorspace=fitz.csGRAY if gray else fitz.csRGB, alpha=False)
    arr = np.frombuffer(pix.samples, np.uint8).reshape(pix.height, pix.width, pix.n)
    return arr[:, :, 0] if gray else cv2.cvtColor(arr, cv2.COLOR_RGB2BGR)


def fit(img, longest):
    h, w = img.shape[:2]
    k = longest / max(h, w)
    return cv2.resize(img, (int(w * k), int(h * k)), interpolation=cv2.INTER_AREA) if k < 1 else img


def ocr_text(img):
    return pytesseract.image_to_string(img, lang=OCR_LANG)


def ocr_lines(img):
    """OCR kèm toạ độ, gộp theo dòng: [{text, x0, y0, x1, y1}]"""
    d = pytesseract.image_to_data(img, lang=OCR_LANG, output_type=pytesseract.Output.DICT)
    lines = {}
    for i, word in enumerate(d['text']):
        if not word.strip() or float(d['conf'][i]) < 30:
            continue
        key = (d['block_num'][i], d['par_num'][i], d['line_num'][i])
        x, y, w, h = d['left'][i], d['top'][i], d['width'][i], d['height'][i]
        line = lines.setdefault(key, {'words': [], 'x0': x, 'y0': y, 'x1': x + w, 'y1': y + h})
        line['words'].append(word)
        line['x0'], line['y0'] = min(line['x0'], x), min(line['y0'], y)
        line['x1'], line['y1'] = max(line['x1'], x + w), max(line['y1'], y + h)
    return [{'text': ' '.join(l['words']), **{k: l[k] for k in ('x0', 'y0', 'x1', 'y1')}} for l in lines.values()]


def pdf_lines(page, scale):
    lines = {}
    for x0, y0, x1, y1, word, block, line_no, _ in page.get_text('words'):
        line = lines.setdefault((block, line_no), {'words': [], 'x0': x0, 'y0': y0, 'x1': x1, 'y1': y1})
        line['words'].append(word)
        line['x0'], line['y0'] = min(line['x0'], x0), min(line['y0'], y0)
        line['x1'], line['y1'] = max(line['x1'], x1), max(line['y1'], y1)
    return [{'text': ' '.join(l['words']), **{k: int(l[k] * scale) for k in ('x0', 'y0', 'x1', 'y1')}} for l in lines.values()]


def inspect_page(out, img, lines, page_no):
    out.setdefault('_images', []).append((page_no, img))   # ảnh trang đã dựng, dùng lại cho mô hình (không trả ra ngoài)
    if is_grayscale(img):
        if 'no_color' not in out['warnings']:
            out['warnings'].append('no_color')   # bản scan đen trắng: không nhận ra màu đỏ của con dấu
    else:
        stamps = find_stamps(img)
        out['stamps'] += [{'page': page_no, **s} for s in stamps]
        if has_blue_ink(img):
            out['signatures'].append({'page': page_no, 'method': 'blue_ink'})
        elif any(signed_over_stamp(img, s) for s in stamps):
            out['signatures'].append({'page': page_no, 'method': 'ink_near_stamp'})
    for block in signature_blocks(img, lines):
        out['signature_blocks'].append({'page': page_no, **block})
        if block['signed']:
            out['signatures'].append({'page': page_no, 'method': 'ink_gap'})


def is_grayscale(img):
    """Không có điểm ảnh nào có màu rõ (bản scan đen trắng hoặc văn bản không dấu/không mực màu)"""
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    colored = (hsv[:, :, 1] >= 60) & (hsv[:, :, 2] >= 60)
    return int(colored.sum()) < 0.0002 * colored.size


def find_stamps(img):
    """Con dấu tròn màu đỏ: vùng đỏ có viền tròn, đường kính ~ 8-35% chiều rộng trang"""
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    h, s, v = cv2.split(hsv)
    red = (((h <= 10) | (h >= 160)) & (s >= 60) & (v >= 60)).astype(np.uint8) * 255
    if cv2.countNonZero(red) == 0:
        return []
    base = min(img.shape[:2])
    merged = cv2.morphologyEx(red, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8), iterations=2)
    contours, _ = cv2.findContours(merged, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    stamps = []
    for c in contours:
        (cx, cy), r = cv2.minEnclosingCircle(c)
        if not 0.04 * base <= r <= 0.18 * base:
            continue
        _, _, bw, bh = cv2.boundingRect(c)
        if not 0.7 <= bw / bh <= 1.4:
            continue
        disk = np.zeros_like(red)
        cv2.circle(disk, (int(cx), int(cy)), int(r), 255, -1)
        ring = np.zeros_like(red)
        cv2.circle(ring, (int(cx), int(cy)), int(r), 255, max(2, int(r * 0.15)))
        fill = cv2.countNonZero(red & disk) / max(1, cv2.countNonZero(disk))
        ring_fill = cv2.countNonZero(red & ring) / max(1, cv2.countNonZero(ring))
        if ring_fill >= 0.2 and 0.06 <= fill <= 0.8:
            stamps.append({'x': round(cx / img.shape[1], 3), 'y': round(cy / img.shape[0], 3),
                           'r': round(r / img.shape[1], 3), 'score': round(ring_fill, 2)})
    return stamps


def signed_over_stamp(img, stamp):
    """
    Chữ ký thường đè lên/cạnh con dấu. Tìm nét bút mực tối (không phải màu đỏ của dấu) liền một khối,
    trải rộng nhưng thưa - khác chữ in (từng ký tự nhỏ rời nhau) và đường kẻ (rất mỏng).
    """
    h, w = img.shape[:2]
    cx, cy, r = stamp['x'] * w, stamp['y'] * h, stamp['r'] * w
    x0, x1 = int(max(0, cx - 0.8 * r)), int(min(w, cx + 2.4 * r))
    y0, y1 = int(max(0, cy - 1.2 * r)), int(min(h, cy + 1.0 * r))
    crop = img[y0:y1, x0:x1]
    hsv = cv2.cvtColor(crop, cv2.COLOR_BGR2HSV)
    red = (hsv[:, :, 1] >= 60) & ((hsv[:, :, 0] <= 10) | (hsv[:, :, 0] >= 160))
    ink = ((cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) < 110) & ~red).astype(np.uint8)
    ink = cv2.dilate(ink, np.ones((3, 3), np.uint8))
    n, _, stats, _ = cv2.connectedComponentsWithStats(ink)
    for i in range(1, n):
        bw, bh, area = stats[i, cv2.CC_STAT_WIDTH], stats[i, cv2.CC_STAT_HEIGHT], stats[i, cv2.CC_STAT_AREA]
        if bw >= 0.6 * r and bh >= 0.3 * r and area / (bw * bh) < 0.3:
            return True
    return False


def has_blue_ink(img):
    """Nét bút mực xanh ở nửa dưới trang (văn bản in hầu như không dùng màu xanh)"""
    lower = img[img.shape[0] // 3:]
    hsv = cv2.cvtColor(lower, cv2.COLOR_BGR2HSV)
    h, s, v = cv2.split(hsv)
    blue = ((h >= 95) & (h <= 135) & (s >= 70) & (v >= 40)).astype(np.uint8)
    if blue.sum() < 0.0005 * blue.size:
        return False
    n, _, stats, _ = cv2.connectedComponentsWithStats(blue)
    widest = max((stats[i, cv2.CC_STAT_WIDTH] for i in range(1, n)), default=0)
    return widest >= 0.04 * img.shape[1]


def signature_blocks(img, lines):
    """
    Tìm chỗ ký: dòng chức danh (HIỆU TRƯỞNG, KT. VIỆN TRƯỞNG, ĐẠI DIỆN BÊN A...) ở nửa dưới trang.
    Khoảng trống bên dưới tới dòng họ tên có nét mực => đã ký; trống trơn => chưa ký.
    """
    if not lines:
        return []
    height = img.shape[0]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
    ink = (gray < 150).astype(np.uint8)
    if img.ndim == 3:   # bỏ màu đỏ của con dấu
        hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
        ink[(hsv[:, :, 1] >= 60) & ((hsv[:, :, 0] <= 10) | (hsv[:, :, 0] >= 160))] = 0
    for l in lines:     # bỏ chữ in
        ink[l['y0']:l['y1'], l['x0']:l['x1']] = 0

    blocks = []
    for title in lines:
        if title['y0'] < height * 0.15 or not SIGNER_TITLE.search(plain(title['text'])):
            continue
        line_h = max(8, title['y1'] - title['y0'])
        below = [l for l in lines if l['y0'] > title['y1'] and l['x0'] < title['x1'] and l['x1'] > title['x0']]
        nxt = min(below, key=lambda l: l['y0'], default=None)
        bottom = nxt['y0'] if nxt else min(height, title['y1'] + 6 * line_h)
        if bottom - title['y1'] < 2.5 * line_h:
            continue   # ngay dưới là chữ: không phải chỗ ký
        pad = int((title['x1'] - title['x0']) * 0.3)
        region = ink[title['y1']:bottom, max(0, title['x0'] - pad):title['x1'] + pad]
        ratio = float(region.mean()) if region.size else 0
        blocks.append({'title': title['text'][:80], 'signed': ratio > 0.004})
    return blocks


VI_LETTERS = set('aàáảãạăằắẳẵặâầấẩẫậbcdđeèéẻẽẹêềếểễệghiìíỉĩịklmnoòóỏõọôồốổỗộơờớởỡợpqrstuùúủũụưừứửữựvxyỳýỷỹỵfjwz')
VI_MARKED = set('àáảãạăằắẳẵặâầấẩẫậđèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵ')


def garbled(text):
    """
    Chữ hỏng: lỗi phông (VIÆN, ¢, §...) hoặc OCR rác lẫn số vào chữ (QUY6T, c6ng, h6 trq).
    Văn bản tiếng Anh hợp lệ không bị coi là hỏng (không có ký tự lạ, ít từ lẫn số).
    """
    letters = [c for c in text.lower() if c.isalpha()]
    if len(letters) < 80:
        return False
    foreign = sum(c not in VI_LETTERS for c in letters) / len(letters)
    words = re.findall(r'\w+', text)
    mixed = sum(bool(re.search(r'[a-zA-Z]', w) and re.search(r'\d', w)) and not re.fullmatch(r'[A-Z]+\d+|\d+[a-zA-Z]{1,3}', w) for w in words)
    return foreign > 0.02 or mixed / max(1, len(words)) > 0.06


def plain(text):
    text = unicodedata.normalize('NFD', text.replace('Đ', 'D').replace('đ', 'd'))
    return ''.join(c for c in text if unicodedata.category(c) != 'Mn').upper()
