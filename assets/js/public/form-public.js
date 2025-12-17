const DateUtil = (window.TPMAPublic = window.TPMAPublic || {}).datetime || {};
const Util = (window.TPMAPublic = window.TPMAPublic || {}).util || {};

const TPMA_CR_API_BASE_PHP = window.TPMAPublicConfig?.apiBase || '';
const TPMA_API_BASE = Util.getApiBase(TPMA_CR_API_BASE_PHP);

let coursesMap = {};
let firstErrorElement = null;
// 預填參數：供 list.html 產生的 course_id / session_id 自動選課
const preselectParams = (() => {
  const search = new URLSearchParams(location.search);
  return {
    courseId: search.get("course_id") || search.get("courseId") || "",
    sessionId: search.get("session_id") || search.get("sessionId") || "",
  };
})();
let prefillingDone = false;

// ===== 工具 =====
// DateUtil and Util are already declared above, no need to redeclare.

function minutesToHoursString(mins) {
  const m = parseInt(mins, 10) || 0;
  if (!m) return "";
  const h = m / 60;
  return Number.isInteger(h) ? `${h} 小時` : `${Math.round(h * 10) / 10} 小時`;
}
function formatSessionLabel(sessionDatetime, durationMinutes) {
  if (!sessionDatetime) return "課程尚在安排中...";
  if (DateUtil.formatRange) {
    const formatted = DateUtil.formatRange(sessionDatetime, durationMinutes);
    if (formatted) return formatted;
  }
  const start = new Date(sessionDatetime.replace(" ", "T"));
  if (isNaN(start.getTime())) return sessionDatetime;
  const dur = parseInt(durationMinutes || 0, 10);
  const end = new Date(start.getTime() + dur * 60000);
  const weekdayMap = ["日", "一", "二", "三", "四", "五", "六"];
  const pad2 = (n) => n.toString().padStart(2, "0");
  const y = start.getFullYear();
  const mo = pad2(start.getMonth() + 1);
  const d = pad2(start.getDate());
  const w = weekdayMap[start.getDay()];
  const sh = pad2(start.getHours());
  const sm = pad2(start.getMinutes());
  const eh = pad2(end.getHours());
  const em = pad2(end.getMinutes());
  return `${y}/${mo}/${d}（${w}） ${sh}:${sm}${dur > 0 ? `~${eh}:${em}` : ""}`;
}
// 手機字串 -> xxxx-xxx-xxx，空白回 ''，格式錯誤回 null
function buildMobile(rawVal) {
  const digits = (rawVal || "").replace(/\D/g, "");
  if (!digits) return "";
  if (digits.length !== 10) return null;
  return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7, 10)}`;
}

// ===== 驗證輔助 =====
function setError(id, msg) {
  const input = document.getElementById(id);
  if (input) input.classList.add("tpma-input-error");
  const err = document.querySelector(`.tpma-error-msg[data-error-for="${id}"]`);
  if (err) err.textContent = msg || "";
  if (!firstErrorElement) firstErrorElement = input || err;
}
function clearAllErrors() {
  firstErrorElement = null;
  document.querySelectorAll(".tpma-input-error").forEach((el) => el.classList.remove("tpma-input-error"));
  document.querySelectorAll(".tpma-error-msg").forEach((el) => (el.textContent = ""));
}

// ===== 課程 / 場次 =====
async function loadCourses() {
  const loadingEl = document.getElementById("tpma-loading");
  loadingEl.textContent = "載入課程中...";
  try {
    const res = await fetch(`${TPMA_API_BASE}/courses`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    let raw = await res.json();
    let data = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);

    const select = document.getElementById("course-select");
    select.innerHTML = '<option value="">請選擇課程</option>';
    coursesMap = {};

    data.forEach((row) => {
      const cid = row.course_id;
      if (!cid) return;
      if (!coursesMap[cid]) {
        coursesMap[cid] = {
          course_id: cid,
          course_name: row.course_name || "",
          lecturer: row.lecturer || "",
          intro: row.intro || "",
          outline: row.outline || "",
          duration_minutes: row.duration_minutes || 0,
          sessions: [],
        };
      }
      if (row.session_id) {
        coursesMap[cid].sessions.push({
          session_id: row.session_id,
          session_datetime: row.session_datetime || "",
        });
      }
    });

    Object.values(coursesMap).forEach((c) => {
      const opt = document.createElement("option");
      opt.value = c.course_id;
      opt.textContent = c.course_name;
      select.appendChild(opt);
    });
    loadingEl.textContent = "";
    select.disabled = false;
    tryPrefillFromQuery();
  } catch (e) {
    console.error(e);
    loadingEl.textContent = "課程載入失敗";
  }
}
function renderCourseInfo(course) {
  document.getElementById("course-name-display").textContent = course.course_name || "";
  document.getElementById("course-lecturer").textContent = course.lecturer || "";
  document.getElementById("course-duration").textContent = minutesToHoursString(course.duration_minutes || 0);
  document.getElementById("course-intro").textContent = course.intro || "";
  const outlineEl = document.getElementById("course-outline");
  outlineEl.innerHTML = course.outline ? marked.parse(course.outline) : "";
}
function onCourseChange() {
  const cid = document.getElementById("course-select").value;
  const course = coursesMap[cid];
  const sessionSelect = document.getElementById("session-select");
  sessionSelect.innerHTML = '<option value="">請選擇授課日期 / 場次</option>';
  sessionSelect.disabled = true;

  document.getElementById("course-name-display").textContent = "";
  document.getElementById("course-lecturer").textContent = "";
  document.getElementById("course-duration").textContent = "";
  document.getElementById("course-intro").textContent = "";
  document.getElementById("course-outline").innerHTML = "";

  if (!course) return;
  renderCourseInfo(course);
  course.sessions.forEach((s) => {
    if (!s.session_id) return;
    const opt = document.createElement("option");
    opt.value = s.session_id;
    opt.textContent = formatSessionLabel(s.session_datetime, course.duration_minutes);
    sessionSelect.appendChild(opt);
  });
  sessionSelect.disabled = false;
}
function tryPrefillFromQuery() {
  if (prefillingDone) return;
  const courseId = preselectParams.courseId;
  if (!courseId || !coursesMap[courseId]) return;
  const courseSelect = document.getElementById("course-select");
  courseSelect.value = courseId;
  onCourseChange();
  if (preselectParams.sessionId) {
    const sessionSelect = document.getElementById("session-select");
    sessionSelect.value = preselectParams.sessionId;
  }
  prefillingDone = true;
}

// ===== 動態學員 =====
let learnerSeq = 0;
function addLearner() {
  learnerSeq++;
  const wrapper = document.getElementById("learners-wrapper");
  const div = document.createElement("div");
  div.className = "tpma-learner";
  div.innerHTML = `
    <div class="tpma-learner-title">學員 ${learnerSeq} <button type="button" class="tpma-btn tpma-btn-secondary tpma-del-learner" style="padding:2px 8px;font-size:12px;float:right;">刪除</button></div>
    <div class="tpma-inline-3">
      <div><div class="tpma-label">姓名</div><input type="text" class="tpma-input learner-name"><div class="tpma-error-msg learner-error-name"></div></div>
      <div><div class="tpma-label">部門</div><input type="text" class="tpma-input learner-dept"></div>
      <div><div class="tpma-label">職稱</div><input type="text" class="tpma-input learner-title"></div>
    </div>
    <div class="tpma-inline-3">
      <div><div class="tpma-label">Email</div><input type="email" class="tpma-input learner-email"><div class="tpma-error-msg learner-error-email"></div></div>
      <div><div class="tpma-label">行動電話</div><input type="text" class="tpma-input learner-mobile"><div class="tpma-error-msg learner-error-mobile"></div></div>
      <div></div>
    </div>
  `;
  wrapper.appendChild(div);
  div.querySelector(".tpma-del-learner").addEventListener("click", () => {
    div.remove();
    renumberLearners();
  });
}
function renumberLearners() {
  const blocks = document.querySelectorAll(".tpma-learner");
  blocks.forEach((blk, idx) => {
    const title = blk.querySelector(".tpma-learner-title");
    if (title) title.childNodes[0].textContent = `學員 ${idx + 1} `;
  });
  if (blocks.length === 0) addLearner();
}

// ===== 驗證整張表，產出 learnersPayload =====
function validateForm() {
  clearAllErrors();
  let ok = true;

  const cid = document.getElementById("course-select").value.trim();
  if (!cid) {
    setError("course-select", "請選擇課程");
    ok = false;
  }

  const sessionSelect = document.getElementById("session-select");
  const sessionValue = sessionSelect.value.trim();
  const sessionText = sessionSelect.options[sessionSelect.selectedIndex]?.text || "";
  if (!sessionValue) {
    setError("session-select", "請選擇授課日期 / 場次（未排定時間不可報名）");
    ok = false;
  }

  const learnerBlocks = document.querySelectorAll(".tpma-learner");
  const learners = [];

  learnerBlocks.forEach((blk) => {
    const nameInput = blk.querySelector(".learner-name");
    const deptInput = blk.querySelector(".learner-dept");
    const titleInput = blk.querySelector(".learner-title");
    const emailInput = blk.querySelector(".learner-email");
    const mobileInput = blk.querySelector(".learner-mobile");
    const eName = blk.querySelector(".learner-error-name");
    const eEmail = blk.querySelector(".learner-error-email");
    const eMobile = blk.querySelector(".learner-error-mobile");

    const name = nameInput.value.trim();
    const dept = deptInput.value.trim();
    const title = titleInput.value.trim();
    const email = emailInput.value.trim();
    const mobileRaw = mobileInput.value;

    eName.textContent = "";
    eEmail.textContent = "";
    eMobile.textContent = "";
    [nameInput, emailInput, mobileInput].forEach((i) => i && i.classList.remove("tpma-input-error"));

    const mobileStr = buildMobile(mobileRaw);

    // 全空白就跳過這塊
    if (!name && !email && !mobileRaw.replace(/\D/g, "") && !dept && !title) return;

    if (!name) {
      eName.textContent = "請填寫學員姓名";
      nameInput.classList.add("tpma-input-error");
      if (!firstErrorElement) firstErrorElement = nameInput;
      ok = false;
    }

    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email) {
      eEmail.textContent = "請填寫學員 Email";
      emailInput.classList.add("tpma-input-error");
      if (!firstErrorElement) firstErrorElement = emailInput;
      ok = false;
    } else if (!emailRe.test(email)) {
      eEmail.textContent = "學員 Email 格式不正確";
      emailInput.classList.add("tpma-input-error");
      if (!firstErrorElement) firstErrorElement = emailInput;
      ok = false;
    }

    if (mobileStr === null) {
      eMobile.textContent = "行動電話需為 10 碼數字，或留空";
      mobileInput.classList.add("tpma-input-error");
      if (!firstErrorElement) firstErrorElement = mobileInput;
      ok = false;
    }

    learners.push({
      name,
      dept,
      title,
      email,
      mobile: mobileStr || "",
    });
  });

  if (learners.length === 0) {
    const err = document.querySelector('.tpma-error-msg[data-error-for="learners"]');
    if (err) err.textContent = "請至少填寫一位學員";
    if (!firstErrorElement) firstErrorElement = document.getElementById("add-learner");
    ok = false;
  }

  if (!ok && firstErrorElement) {
    const rect = firstErrorElement.getBoundingClientRect();
    const top = window.pageYOffset + rect.top - 80;
    window.scrollTo({ top, behavior: "smooth" });
  }

  return {
    ok,
    cid,
    sessionValue,
    sessionText,
    learners,
    shared: {
      source: document.getElementById("source").value,
      note: document.getElementById("note").value.trim(),
    },
  };
}

// ===== Summary Modal：顯示多學員確認 =====
function showSummaryModal(courseInfo, form) {
  const backdrop = document.createElement("div");
  backdrop.className = "tpma-summary-modal-backdrop";
  const m = document.createElement("div");
  m.className = "tpma-summary-modal";

  const sharedRows = `
    <tr><th>課程名稱</th><td>${courseInfo.course_name}</td></tr>
    <tr><th>授課日期 / 場次</th><td>${courseInfo.class_date || ""}</td></tr>
    <tr><th>講師</th><td>${courseInfo.lecturer || ""}</td></tr>
    <tr><th>資訊來源</th><td>${form.shared.source || ""}</td></tr>
    <tr><th>備註</th><td>${(form.shared.note || "").replace(/\n/g, "<br>")}</td></tr>
  `;
  const learnersRows = form.learners
    .map(
      (l, idx) => `
    <tr>
      <th>學員 ${idx + 1}</th>
      <td>
        姓名：${l.name}<br>
        部門／職稱：${l.dept || ""}／${l.title || ""}<br>
        Email：${l.email}<br>
        行動電話：${l.mobile || ""}<br>
      </td>
    </tr>`
    )
    .join("");

  m.innerHTML = `
    <h3>請確認以下報名資訊（共 ${form.learners.length} 位學員）</h3>
    <table class="tpma-summary-table">
      <tbody>
        ${sharedRows}
        ${learnersRows}
      </tbody>
    </table>
    <div class="tpma-summary-actions">
      <button type="button" class="tpma-btn tpma-cancel">返回修改</button>
      <button type="button" class="tpma-btn tpma-confirm">確認送出</button>
    </div>
  `;
  backdrop.appendChild(m);
  document.body.appendChild(backdrop);
  // Trigger fade-in after insertion so CSS transitions run and the modal becomes visible.
  requestAnimationFrame(() => {
    backdrop.classList.add("open");
    m.classList.add("open");
  });

  return new Promise((resolve) => {
    m.querySelector(".tpma-cancel").onclick = () => {
      document.body.removeChild(backdrop);
      resolve(false);
    };
    m.querySelector(".tpma-confirm").onclick = () => {
      document.body.removeChild(backdrop);
      resolve(true);
    };
  });
}

// ===== 送 Woo checkout init =====
async function submitMultipleRegistrations(courseInfo, form) {
  const msgBox = document.getElementById("tpma-message");
  const btn = document.getElementById("tpma-submit");
  msgBox.innerHTML = "";
  btn.disabled = true;

  const payload = {
    course_id: parseInt(form.cid, 10),
    session_id: form.sessionValue || "",
    learners: form.learners.map((l) => ({
      student_name: l.name,
      department: l.dept,
      job_title: l.title,
      mobile: l.mobile,
      emails: l.email,
    })),
    source: form.shared.source,
    note: form.shared.note,
  };

  try {
    const res = await fetch(`${TPMA_API_BASE}/checkout-init`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.checkout_url) throw new Error(data.message || data.code || "送出失敗，請稍後再試。");
    window.location = data.checkout_url;
  } catch (e) {
    console.error(e);
    msgBox.innerHTML = `<div class="tpma-alert tpma-alert-error">${e.message}</div>`;
    btn.disabled = false;
  }
}

// ===== 綁定事件 =====
document.getElementById("course-select").addEventListener("change", onCourseChange);
document.getElementById("add-learner").addEventListener("click", () => addLearner());
document.getElementById("tpma-submit").addEventListener("click", async () => {
  const v = validateForm();
  if (!v.ok) return;
  const c = coursesMap[v.cid];
  const courseInfo = {
    course_name: c?.course_name || "",
    lecturer: c?.lecturer || "",
    class_date: v.sessionText || "",
  };
  const confirmed = await showSummaryModal(courseInfo, v);
  if (!confirmed) return;
  await submitMultipleRegistrations(courseInfo, v);
});

// 初始
addLearner();
loadCourses();
