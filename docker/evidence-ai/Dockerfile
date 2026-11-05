# Dịch vụ đọc tệp minh chứng (chạy CPU): trích chữ, OCR tiếng Việt, tìm con dấu/chữ ký.
# LibreOffice: đọc Word/Excel/PowerPoint đời cũ, OpenDocument, RTF...; unar: giải nén rar/7z/tar...
# Chỉ mở trong mạng nội bộ docker, không gửi dữ liệu ra ngoài.
FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-vie \
       libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui fonts-dejavu-core unar \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py .

USER nobody
EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
