let CRITERIA = {};
const formState = {};

const STORAGE_KEY = "score_system_form_state_v1";
let logBuffer = [];
let logTimer = null;

function sendClientLog(type, message = "", context = {}, level = "INFO") {
    const payload = {
        type,
        message,
        context,
        level,
        time_client: new Date().toISOString()
    };

    try {
        fetch("log_event.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(() => {});
    } catch (e) {}
}

function queueClientLog(type, message = "", context = {}, level = "INFO") {
    logBuffer.push({
        type,
        message,
        context,
        level,
        time_client: new Date().toISOString()
    });

    if (logTimer) return;

    logTimer = setTimeout(() => {
        const batch = logBuffer.splice(0, logBuffer.length);
        logTimer = null;

        sendClientLog("CLIENT_LOG_BATCH", "Ghi nhận hoạt động người dùng trên form", {
            events: batch
        });
    }, 3000);
}

function saveStateToLocalStorage() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(formState));
    } catch (e) {
        sendClientLog("LOCAL_STORAGE_SAVE_ERROR", "Không thể lưu form vào localStorage", {
            error: String(e)
        }, "WARN");
    }
}

function loadStateFromLocalStorage() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) return;

        const saved = JSON.parse(raw);

        if (saved && typeof saved === "object") {
            Object.assign(formState, saved);
        }
    } catch (e) {
        sendClientLog("LOCAL_STORAGE_LOAD_ERROR", "Không thể đọc form từ localStorage", {
            error: String(e)
        }, "WARN");
    }
}

function clearSavedFormState() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch (e) {}
}

async function loadCriteria() {
    loadStateFromLocalStorage();

    const res = await fetch("criteria.json");
    CRITERIA = await res.json();

    initOrganizationName();
    initCheckboxes();
    restoreSelectedFunctions();

    queueClientLog("FORM_PAGE_READY", "Form đánh giá đã tải xong");
}

function initOrganizationName() {
    const orgInput = document.getElementById("organization_name");

    if (!orgInput) return;

    orgInput.value = getValue("organization_name");

    orgInput.addEventListener("input", function () {
        saveValue("organization_name", this.value);

        queueClientLog("FORM_FIELD_UPDATED", "Người dùng đã nhập tên đơn vị", {
            field: "organization_name",
            has_value: this.value.trim() !== "",
            length: this.value.trim().length
        });
    });
}

function initCheckboxes() {
    document.querySelectorAll("#function-checkboxes input")
        .forEach(cb => {
            cb.addEventListener("change", function () {
                saveSelectedFunctions();
                updateForm();

                queueClientLog("FORM_FUNCTION_TOGGLE", "Người dùng thay đổi lựa chọn chức năng", {
                    function_type: this.value,
                    checked: this.checked
                });
            });
        });
}

function saveSelectedFunctions() {
    const selected = [...document.querySelectorAll("#function-checkboxes input")]
        .filter(cb => cb.checked)
        .map(cb => cb.value);

    saveValue("selected_functions", selected);
}

function restoreSelectedFunctions() {
    const selected = getValue("selected_functions");

    if (Array.isArray(selected)) {
        document.querySelectorAll("#function-checkboxes input").forEach(cb => {
            cb.checked = selected.includes(cb.value);
        });

        updateForm();
    }
}

function updateForm() {
    saveCurrentVisibleValues();

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
    saveStateToLocalStorage();
}

function getValue(path) {
    return path
        .split(".")
        .reduce((obj, key) => obj?.[key], formState) ?? "";
}

function saveCurrentVisibleValues() {
    const orgInput = document.getElementById("organization_name");

    if (orgInput) {
        saveValue("organization_name", orgInput.value);
    }

    document.querySelectorAll("#form-area input, #form-area select, #form-area textarea").forEach(el => {
        if (!el.name || el.type === "file") return;

        const path = el.dataset.statePath;

        if (path) {
            saveValue(path, el.value);
        }
    });
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
                saveSectionValues(section);
                section.style.display = "none";
                setSectionDisabled(section, true);
            }
        }
    });
}

function saveSectionValues(section) {
    section.querySelectorAll("input, select, textarea").forEach(el => {
        if (!el.name || el.type === "file") return;

        const path = el.dataset.statePath;

        if (path) {
            saveValue(path, el.value);
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
                data-state-path="${weightPath}"
                oninput="saveValue('${weightPath}', this.value); queueClientLog('FORM_FIELD_UPDATED', 'Người dùng nhập trọng số', { function_type: '${type}', field: 'weight', has_value: this.value.trim() !== '' })">
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

    let html = `<div class="question-row" data-function-type="${type}" data-question-id="${q.id}">`;

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
                data-state-path="${basePath}.yes"
                onchange="saveValue('${basePath}.yes', this.value); queueClientLog('FORM_QUESTION_ANSWERED', 'Người dùng chọn Có/Không cho tiêu chí', { function_type: '${type}', group: '${groupId}', question_id: '${q.id}', value: this.value })">
                <option value="">-- Chọn --</option>
                <option value="1" ${yesVal === "1" ? "selected" : ""}>Có</option>
                <option value="0" ${yesVal === "0" ? "selected" : ""}>Không</option>
            </select>
        </div>
    `;

    if (q.inputs) {
        q.inputs.forEach(input => {
            const inputPath = `${basePath}.inputs.${input.name}`;
            const val = getValue(inputPath);

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
                        data-state-path="${inputPath}"
                        oninput="saveValue('${inputPath}', this.value); queueClientLog('FORM_FIELD_UPDATED', 'Người dùng nhập số liệu tiêu chí', { function_type: '${type}', group: '${groupId}', question_id: '${q.id}', input_name: '${input.name}', has_value: this.value.trim() !== '' })">
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
                    data-state-path="${basePath}.note"
                    oninput="saveValue('${basePath}.note', this.value); queueClientLog('FORM_FIELD_UPDATED', 'Người dùng nhập chú thích tiêu chí', { function_type: '${type}', group: '${groupId}', question_id: '${q.id}', field: 'note', has_value: this.value.trim() !== '', length: this.value.trim().length })"
                    placeholder="${q.note_placeholder || 'Nhập thông tin bổ sung...'}">${noteVal}</textarea>
            </div>
        `;
    }

    html += `
        <div class="evidence-box">
            <label>Minh chứng <span style="color:red">*</span></label>

            <textarea
                name="evidence_text[${type}][${q.id}]"
                data-state-path="${basePath}.evidence_text"
                oninput="saveValue('${basePath}.evidence_text', this.value); queueClientLog('FORM_FIELD_UPDATED', 'Người dùng nhập mô tả minh chứng', { function_type: '${type}', group: '${groupId}', question_id: '${q.id}', field: 'evidence_text', has_value: this.value.trim() !== '', length: this.value.trim().length })"
                placeholder="Nhập mô tả minh chứng, số quyết định, đường link, số văn bản... hoặc tải tệp bên dưới">${evidenceTextVal}</textarea>

            <input
                type="file"
                multiple
                name="evidence_${type}_${q.id}[]"
                onchange="queueClientLog('FORM_EVIDENCE_FILE_SELECTED', 'Người dùng chọn file minh chứng', { function_type: '${type}', group: '${groupId}', question_id: '${q.id}', file_count: this.files ? this.files.length : 0 })">

            <small>
                Có thể nhập mô tả, tải tệp hoặc cả hai. Hỗ trợ ảnh, PDF, Word, Excel, ZIP...
                <br><em>Lưu ý: file đã chọn không thể tự khôi phục sau khi tải lại trang do chính sách bảo mật của trình duyệt.</em>
            </small>
        </div>
    `;

    html += `</div>`;

    return html;
}

function validateForm() {
    saveCurrentVisibleValues();

    const orgInput = document.getElementById("organization_name");
    const orgName = orgInput ? orgInput.value.trim() : "";

    if (orgName === "") {
        queueClientLog("FORM_VALIDATE_FAIL", "Thiếu tên đơn vị đánh giá", {
            field: "organization_name"
        }, "WARN");

        alert("Vui lòng nhập tên đơn vị đánh giá.");
        if (orgInput) orgInput.focus();
        return false;
    }

    const checkedTypes = [...document.querySelectorAll("#function-checkboxes input")]
        .filter(cb => cb.checked)
        .map(cb => cb.value);

    if (checkedTypes.length === 0) {
        queueClientLog("FORM_VALIDATE_FAIL", "Chưa chọn chức năng", {}, "WARN");
        alert("Phải chọn ít nhất 1 chức năng.");
        return false;
    }

    let weightSum = 0;

    checkedTypes.forEach(type => {
        const weightInput = document.querySelector(`input[name="weight[${type}]"]`);
        weightSum += Number(weightInput?.value || 0);
    });

    if (weightSum !== 100) {
        queueClientLog("FORM_VALIDATE_FAIL", "Tổng trọng số không bằng 100", {
            weight_sum: weightSum
        }, "WARN");

        alert("Tổng trọng số các chức năng đang chọn phải bằng 100%.");
        return false;
    }

    for (const type of checkedTypes) {
        const section = document.getElementById(`section-${type}`);

        const selects = section.querySelectorAll("select");

        for (const s of selects) {
            if (s.value === "") {
                queueClientLog("FORM_VALIDATE_FAIL", "Thiếu Có/Không cho tiêu chí", {
                    function_type: type,
                    name: s.name
                }, "WARN");

                alert("Vui lòng chọn Có/Không cho tất cả câu hỏi đang hiển thị.");
                s.focus();
                return false;
            }
        }

        const numberInputs = section.querySelectorAll('input[type="number"]');

        for (const n of numberInputs) {
            if (n.value.trim() === "") {
                queueClientLog("FORM_VALIDATE_FAIL", "Thiếu ô số liệu hoặc trọng số", {
                    function_type: type,
                    name: n.name
                }, "WARN");

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
                queueClientLog("FORM_VALIDATE_FAIL", "Thiếu chú thích/ghi chú", {
                    function_type: type,
                    name: t.name
                }, "WARN");

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
                queueClientLog("FORM_VALIDATE_FAIL", "Thiếu minh chứng", {
                    function_type: type,
                    question_id: row.dataset.questionId
                }, "WARN");

                alert("Mỗi tiêu chí phải có ít nhất một minh chứng: mô tả minh chứng hoặc tệp đính kèm.");

                if (evidenceTextarea) {
                    evidenceTextarea.focus();
                }

                return false;
            }
        }
    }

    sendClientLog("FORM_SUBMIT_START", "Người dùng bắt đầu gửi form", {
        organization_has_value: orgName !== "",
        selected_functions: checkedTypes
    });

    return true;
}

window.addEventListener("beforeunload", function () {
    saveCurrentVisibleValues();

    if (logBuffer.length > 0) {
        const batch = logBuffer.splice(0, logBuffer.length);

        try {
            navigator.sendBeacon(
                "log_event.php",
                new Blob([JSON.stringify({
                    type: "CLIENT_LOG_BATCH_BEFORE_UNLOAD",
                    message: "Gửi log trước khi rời trang",
                    context: { events: batch },
                    level: "INFO"
                })], { type: "application/json" })
            );
        } catch (e) {}
    }
});

window.clearScoreSystemDraft = clearSavedFormState;

loadCriteria();