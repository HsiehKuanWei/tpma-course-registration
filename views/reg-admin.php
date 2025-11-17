<?php
$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>
<style>
.tpma-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
.tpma-table th, .tpma-table td { border: 1px solid #ddd; padding: 4px 6px; vertical-align: top; }
.tpma-filter { margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.tpma-filter input, .tpma-filter select { padding: 3px 6px; font-size: 13px; }
.tpma-btn { padding: 3px 8px; font-size: 12px; cursor: pointer; margin-left: 4px; }
.tpma-input { width: 100%; box-sizing: border-box; font-size: 13px; }
.tpma-select { font-size: 13px; }
</style>

<div id="tpma-reg-admin">
    <div class="tpma-filter">
        <input type="text" id="tpma-reg-filter-regno" placeholder="報名編號（模糊）">
        <input type="text" id="tpma-reg-filter-course" placeholder="課程名稱（模糊）">
        <input type="text" id="tpma-reg-filter-name" placeholder="學員姓名（模糊）">
        <select id="tpma-reg-filter-status">
            <option value="">狀態(全部)</option>
            <option value="pending">pending</option>
            <option value="submitted">submitted</option>
            <option value="paid">paid</option>
            <option value="cancelled">cancelled</option>
        </select>
        <button class="tpma-btn" id="tpma-reg-search">查詢</button>
    </div>

    <table class="tpma-table">
        <thead>
            <tr>
                <th>編輯</th>
                <th>報名編號</th>
                <th>報名時間</th>
                <th>課程名稱</th>
                <th>授課講師</th>
                <th>授課日期</th>
                <th>學員姓名</th>
                <th>公司抬頭</th>
                <th>統編</th>
                <th>部門</th>
                <th>職稱</th>
                <th>電話</th>
                <th>Email</th>
                <th>收件人</th>
                <th>地址</th>
                <th>資訊來源</th>
                <th>備註</th>
            </tr>
        </thead>
        <tbody id="tpma-reg-body">
            <tr><td colspan="17">請先查詢。</td></tr>
        </tbody>
    </table>
</div>

<script>
(function(){
    const apiBase = '<?php echo $apiBase; ?>';
    const wpRestNonce = '<?php echo $restNonce; ?>';

    function display(val) {
        return val == null ? '' : val;
    }

    async function fetchRegistrations() {
        const params = new URLSearchParams();
        const regno = document.getElementById('tpma-reg-filter-regno').value.trim();
        const course = document.getElementById('tpma-reg-filter-course').value.trim();
        const name = document.getElementById('tpma-reg-filter-name').value.trim();
        const status = document.getElementById('tpma-reg-filter-status').value;

        if (regno) params.append('reg_no', regno);
        if (course) params.append('course_name', course);
        if (name) params.append('student_name', name);
        if (status) params.append('status', status);

        const url = apiBase + '/admin/registrations?' + params.toString();

        const res = await fetch(url, {
            credentials: 'include',
            headers: { 'X-WP-Nonce': wpRestNonce }
        });

        const data = await res.json();
        renderTable(data);
    }

    function renderTable(rows) {
        const tbody = document.getElementById('tpma-reg-body');
        tbody.innerHTML = '';

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="17">查無資料</td></tr>';
            return;
        }

        rows.forEach(row => {
            const tr = document.createElement('tr');
            tr.dataset.id = row.id;

            // 編輯按鈕（進入/儲存）
            const editTd = document.createElement('td');
            const editBtn = document.createElement('button');
            editBtn.textContent = '編輯';
            editBtn.className = 'tpma-btn';
            editBtn.onclick = () => toggleEdit(tr);
            editTd.appendChild(editBtn);
            tr.appendChild(editTd);

            tr.innerHTML += `
                <td>${display(row.reg_no)}</td>
                <td>${display(row.created_at)}</td>
                <td class="editable" data-field="course_name">${display(row.course_name)}</td>
                <td class="editable" data-field="lecturer">${display(row.lecturer)}</td>
                <td class="editable" data-field="class_date">${display(row.class_date)}</td>
                <td class="editable" data-field="student_name">${display(row.student_name)}</td>
                <td class="editable" data-field="company_name">${display(row.company_name)}</td>
                <td class="editable" data-field="tax_id">${display(row.tax_id)}</td>
                <td class="editable" data-field="department">${display(row.department)}</td>
                <td class="editable" data-field="job_title">${display(row.job_title)}</td>
                <td class="editable" data-field="phone">${display(row.phone)}</td>
                <td class="editable" data-field="emails">${display(row.emails)}</td>
                <td class="editable" data-field="receiver">${display(row.receiver)}</td>
                <td class="editable" data-field="address">${display(row.address)}</td>
                <td class="editable" data-field="source">${display(row.source)}</td>
                <td class="editable" data-field="note">${display(row.note)}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    function toggleEdit(tr) {
        const isEditing = tr.classList.contains('editing');
        const btn = tr.querySelector('td:first-child button');

        if (!isEditing) {
            tr.classList.add('editing');
            btn.textContent = '儲存';

            tr.querySelectorAll('.editable').forEach(td => {
                const field = td.dataset.field;
                const value = td.textContent.trim();

                if (field === 'source') {
                    td.innerHTML = `
                        <select class="tpma-input tpma-select">
                            <option value="">請選擇</option>
                            <option value="官網">官網</option>
                            <option value="Email">Email</option>
                            <option value="LINE">LINE</option>
                            <option value="FB">FB</option>
                            <option value="其他">其他</option>
                        </select>
                    `;
                    td.querySelector('select').value = value;
                } else {
                    td.innerHTML = `<input class="tpma-input" value="${value.replace(/"/g,'&quot;')}">`;
                }
            });

        } else {
            saveRow(tr);
        }
    }

    async function saveRow(tr) {
        const id = parseInt(tr.dataset.id, 10);
        const payload = { id };

        tr.querySelectorAll('.editable').forEach(td => {
            const field = td.dataset.field;
            const input = td.querySelector('input,select');
            if (input) {
                payload[field] = input.value.trim();
            }
        });

        const res = await fetch(apiBase + '/admin/registration/update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpRestNonce
            },
            credentials: 'include',
            body: JSON.stringify(payload)
        });

        const result = await res.json();
        if (result && result.success) {
            await fetchRegistrations();
        } else {
            alert(result && result.message ? result.message : '儲存失敗');
        }
    }

    document.getElementById('tpma-reg-search').addEventListener('click', fetchRegistrations);
})();
</script>
