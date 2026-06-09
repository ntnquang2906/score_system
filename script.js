let CRITERIA = {};
const formState = {};

async function loadCriteria() {
    const res = await fetch("criteria.json");
    CRITERIA = await res.json();
    initCheckboxes();
}

function initCheckboxes() {
    document.querySelectorAll("#function-checkboxes input")
        .forEach(cb => cb.addEventListener("change", updateForm));
}

function updateForm() {
    const selected = [...document.querySelectorAll("#function-checkboxes input")]
        .filter(cb => cb.checked)
        .map(cb => cb.value);

    renderForms(selected);
    updateHidden(selected);
}

function updateHidden(selected) {
    const container = document.getElementById("hidden-inputs");
    container.innerHTML = "";

    selected.forEach(type => {
        container.innerHTML += `<input type="hidden" name="function_type[]" value="${type}">`;
    });
}

function saveValue(path, value) {
    const keys = path.split(".");
    let obj = formState;

    while (keys.length > 1) {
        const k = keys.shift();
        if (!obj[k]) obj[k] = {};
        obj = obj[k];
    }

    obj[keys[0]] = value;
}

function getValue(path) {
    return path
        .split(".")
        .reduce((obj, key) => obj?.[key], formState) ?? "";
}

function renderForms(selected) {
    const area = document.getElementById("form-area");

    selected.forEach(type => {
        let section = document.getElementById(`section-${type}`);

        if (!section) {
            area.innerHTML += renderFunction(type);
            section = document.getElementById(`section-${type}`);
        }

        section.style.display = "block";
        setSectionDisabled(section, false);
    });

    Object.keys(CRITERIA.functions).forEach(type => {
        if (!selected.includes(type)) {
            const section = document.getElementById(`section-${type}`);

            if (section) {
                section.style.display = "none";
                setSectionDisabled(section, true);
            }
        }
    });
}

function setSectionDisabled(section, disabled) {
    section
        .querySelectorAll("input, select, textarea")
        .forEach(el => el.disabled = disabled);
}

function renderFunction(type) {
    const func = CRITERIA.functions[type];
    const weightPath = `${type}.weight`;
    const weightVal = getValue(weightPath);

    let html = `
        <section class="function-card" id="section-${type}">
            <h2>${func.name}</h2>
    `;

    html += `
        <div class="weight-box">
            <label>Trọng số (%) <span style="color:red">*</span></label>
            <input
                type="number"
                name="weight[${type}]"
                min="0"
                max="100"
                value="${weightVal}"
                required
                oninput="saveValue('${weightPath}', this.value)">
        </div>
    `;

    func.groups.forEach(group => {
        html += `
            <div class="group-block">
                <h3>${group.title} (${group.max} điểm)</h3>
        `;

        group.criteria.forEach(q => {
            html += renderQuestion(type, group.id, q);
        });

        html += `</div>`;
    });

    html += `</section>`;

    return html;
}

function renderQuestion(type, groupId, q) {
    const basePath = `${type}.${q.id}`;
    const yesVal = getValue(`${basePath}.yes`);
    const noteVal = getValue(`${basePath}.note`);
    const evidenceTextVal = getValue(`${basePath}.evidence_text`);

    const isQuantitative =
        q.display_mode === "quantitative" || !!q.inputs;

    let html = `<div class="question-row">`;

    html += `
        <div class="q-main">
            <strong>${q.text}</strong>
            <small>Nhóm: ${groupId} | Tối đa: ${q.max} điểm</small>
        </div>
    `;

    html += `
        <div>
            <label>Đáp án <span style="color:red">*</span></label>
            <select
                required
                name="answers[${type}][${q.id}][yes]"
                onchange="saveValue('${basePath}.yes', this.value)">
                <option value="">-- Chọn --</option>
                <option value="1" ${yesVal === "1" ? "selected" : ""}>Có</option>
                <option value="0" ${yesVal === "0" ? "selected" : ""}>Không</option>
            </select>
        </div>
    `;

    if (q.inputs) {
        q.inputs.forEach(input => {
            const val = getValue(`${basePath}.inputs.${input.name}`);

            html += `
                <div>
                    <label>${input.label} <span style="color:red">*</span></label>
                    <input
                        type="number"
                        step="any"
                        min="0"
                        required
                        name="answers[${type}][${q.id}][inputs][${input.name}]"
                        value="${val}"
                        oninput="saveValue('${basePath}.inputs.${input.name}', this.value)">
                </div>
            `;
        });
    }

    if (!isQuantitative) {
        html += `
            <div>
                <label>Chú thích <span style="color:red">*</span></label>
                <textarea
                    required
                    name="answers[${type}][${q.id}][note]"
                    oninput="saveValue('${basePath}.note', this.value)"
                    placeholder="${q.note_placeholder || 'Nhập thông tin bổ sung...'}">${noteVal}</textarea>
            </div>
        `;
    }

    html += `
        <div class="evidence-box">
            <label>Minh chứng <span style="color:red">*</span></label>

            <textarea
                name="evidence_text[${type}][${q.id}]"
                oninput="saveValue('${basePath}.evidence_text', this.value)"
                placeholder="Nhập mô tả minh chứng, số quyết định, đường link, số văn bản... hoặc tải tệp bên dưới">${evidenceTextVal}</textarea>

            <input
                type="file"
                multiple
                name="evidence_${type}_${q.id}[]">

            <small>
                Có thể nhập mô tả, tải tệp hoặc cả hai. Hỗ trợ ảnh, PDF, Word, Excel, ZIP...
            </small>
        </div>
    `;

    html += `</div>`;

    return html;
}

function validateForm() {
    const checkedTypes = [...document.querySelectorAll("#function-checkboxes input")]
        .filter(cb => cb.checked)
        .map(cb => cb.value);

    if (checkedTypes.length === 0) {
        alert("Phải chọn ít nhất 1 chức năng.");
        return false;
    }

    let weightSum = 0;

    checkedTypes.forEach(type => {
        const weightInput = document.querySelector(`input[name="weight[${type}]"]`);
        weightSum += Number(weightInput?.value || 0);
    });

    if (weightSum !== 100) {
        alert("Tổng trọng số các chức năng đang chọn phải bằng 100%.");
        return false;
    }

    for (const type of checkedTypes) {
        const section = document.getElementById(`section-${type}`);

        const selects = section.querySelectorAll("select");

        for (const s of selects) {
            if (s.value === "") {
                alert("Vui lòng chọn Có/Không cho tất cả câu hỏi đang hiển thị.");
                s.focus();
                return false;
            }
        }

        const numberInputs = section.querySelectorAll('input[type="number"]');

        for (const n of numberInputs) {
            if (n.value.trim() === "") {
                alert("Vui lòng điền đầy đủ các ô số liệu/trọng số đang hiển thị.");
                n.focus();
                return false;
            }
        }

        const textareas = section.querySelectorAll("textarea");

        for (const t of textareas) {
            if (
                !t.name.startsWith("evidence_text") &&
                t.value.trim() === ""
            ) {
                alert("Vui lòng nhập đầy đủ phần Chú thích/Ghi chú đang hiển thị.");
                t.focus();
                return false;
            }
        }

        const rows = section.querySelectorAll(".question-row");

        for (const row of rows) {
            const fileInput = row.querySelector('input[type="file"]');
            const evidenceTextarea = row.querySelector('textarea[name^="evidence_text"]');

            const hasFile =
                fileInput &&
                fileInput.files &&
                fileInput.files.length > 0;

            const hasText =
                evidenceTextarea &&
                evidenceTextarea.value.trim() !== "";

            if (!hasFile && !hasText) {
                alert("Mỗi tiêu chí phải có ít nhất một minh chứng: mô tả minh chứng hoặc tệp đính kèm.");

                if (evidenceTextarea) {
                    evidenceTextarea.focus();
                }

                return false;
            }
        }
    }

    return true;
}

loadCriteria();