<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
  <div id="tpma-report-admin" class="tpma-wrap tpma-report-wrap">
    <header class="tpma-report-header">
      <div>
        <h1>TPMA 統計報表</h1>
        <p>依年度、月份、課程與狀態快速檢視營運、財務與教務數據。</p>
      </div>
      <button type="button" class="tpma-btn tpma-report-refresh" id="tpma-report-refresh">重新整理</button>
    </header>

    <section class="tpma-report-filters" aria-label="報表篩選">
      <div class="tpma-report-filter-grid">
        <label>
          <span>年度</span>
          <select id="tpma-report-year"></select>
        </label>
        <label>
          <span>月份</span>
          <select id="tpma-report-month">
            <option value="">全年</option>
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <option value="<?php echo esc_attr((string)$i); ?>"><?php echo esc_html((string)$i); ?> 月</option>
            <?php endfor; ?>
          </select>
        </label>
        <label>
          <span>日期起</span>
          <input type="date" id="tpma-report-date-from">
        </label>
        <label>
          <span>日期訖</span>
          <input type="date" id="tpma-report-date-to">
        </label>
        <label>
          <span>課程</span>
          <select id="tpma-report-course">
            <option value="">全部課程</option>
          </select>
        </label>
        <label>
          <span>講師</span>
          <select id="tpma-report-lecturer">
            <option value="">全部講師</option>
          </select>
        </label>
        <label>
          <span>付款狀態</span>
          <select id="tpma-report-payment-status">
            <option value="">全部</option>
            <option value="legacy">舊資料</option>
            <option value="pending">待付款</option>
            <option value="on-hold">未付款</option>
            <option value="processing">待核帳</option>
            <option value="completed">已付款</option>
            <option value="cancelled">已取消</option>
            <option value="refunded">已退款</option>
            <option value="failed">失敗</option>
          </select>
        </label>
        <label>
          <span>報名狀態</span>
          <select id="tpma-report-status">
            <option value="">全部</option>
            <option value="cert_pending">待發證</option>
            <option value="cert_ready">待寄證</option>
            <option value="completed">已結訓</option>
            <option value="hold">保留中</option>
            <option value="hold_refunded">待退款</option>
            <option value="postpay">課後付款</option>
            <option value="cancelled">已取消</option>
          </select>
        </label>
      </div>
      <div class="tpma-report-filter-actions">
        <button type="button" class="tpma-btn" id="tpma-report-apply">套用篩選</button>
        <button type="button" class="tpma-btn tpma-btn-secondary" id="tpma-report-clear">清除篩選</button>
      </div>
    </section>

    <div class="tpma-report-state" id="tpma-report-state" aria-live="polite">載入中...</div>

    <section class="tpma-report-kpis" id="tpma-report-kpis" aria-label="關鍵數字"></section>

    <nav class="tpma-report-tabs" role="tablist" aria-label="報表類型">
      <button type="button" class="tpma-report-tab is-active" data-report-tab="operations" role="tab" aria-selected="true">營運</button>
      <button type="button" class="tpma-report-tab" data-report-tab="finance" role="tab" aria-selected="false">財務</button>
      <button type="button" class="tpma-report-tab" data-report-tab="comparisons" role="tab" aria-selected="false">比較器</button>
    </nav>

    <section class="tpma-report-panel is-active" data-report-panel="operations" role="tabpanel">
      <div class="tpma-report-chart-grid">
        <article class="tpma-report-card">
          <h2>年分析</h2>
          <div class="tpma-chart-box"><canvas id="tpma-chart-year-analysis"></canvas></div>
          <div class="tpma-report-table-wrap"><table id="tpma-table-year-analysis"></table></div>
        </article>
        <article class="tpma-report-card">
          <h2>月分析</h2>
          <div class="tpma-chart-box"><canvas id="tpma-chart-month-analysis"></canvas></div>
          <div class="tpma-report-table-wrap"><table id="tpma-table-month-analysis"></table></div>
        </article>
        <article class="tpma-report-card tpma-report-card-wide">
          <h2>營運總覽圖表</h2>
          <div class="tpma-report-card-toolbar" aria-label="營運總覽設定">
            <label class="tpma-report-control">
              <span>群組</span>
              <select id="tpma-overview-group">
                <option value="course">課程群組</option>
                <option value="lecturer">講師群組</option>
              </select>
            </label>
            <div class="tpma-report-metric-toggles" aria-label="營運總覽指標">
              <label><input type="checkbox" class="tpma-overview-metric" data-metric="learners" checked> 人數</label>
              <label><input type="checkbox" class="tpma-overview-metric" data-metric="class_count" checked> 班數</label>
              <label><input type="checkbox" class="tpma-overview-metric" data-metric="total_revenue" checked> 總收入</label>
              <label><input type="checkbox" class="tpma-overview-metric" data-metric="lecturer_fee" checked> 講師費</label>
              <label><input type="checkbox" class="tpma-overview-metric" data-metric="net_revenue" checked> 淨收入</label>
            </div>
          </div>
          <div class="tpma-chart-box"><canvas id="tpma-chart-operations-overview"></canvas></div>
          <div class="tpma-report-table-wrap" id="tpma-table-operations-overview"></div>
        </article>
        <article class="tpma-report-card tpma-report-card-wide">
          <h2>未開班分析</h2>
          <div class="tpma-report-card-toolbar" aria-label="未開班分析設定">
            <label class="tpma-report-control">
              <span>群組</span>
              <select id="tpma-unopened-group">
                <option value="course">課程群組</option>
                <option value="lecturer">講師群組</option>
              </select>
            </label>
            <div class="tpma-report-metric-toggles" aria-label="未開班分析指標">
              <label><input type="checkbox" class="tpma-unopened-metric" data-metric="learners" checked> 人數</label>
              <label><input type="checkbox" class="tpma-unopened-metric" data-metric="class_count" checked> 班數</label>
              <label><input type="checkbox" class="tpma-unopened-metric" data-metric="total_revenue" checked> 總收入</label>
              <label><input type="checkbox" class="tpma-unopened-metric" data-metric="lecturer_fee" checked> 講師費</label>
              <label><input type="checkbox" class="tpma-unopened-metric" data-metric="net_revenue" checked> 淨收入</label>
            </div>
          </div>
          <div class="tpma-chart-box"><canvas id="tpma-chart-unopened"></canvas></div>
          <div class="tpma-report-table-wrap" id="tpma-table-unopened"></div>
        </article>
      </div>
    </section>

    <section class="tpma-report-panel" data-report-panel="finance" role="tabpanel">
      <div class="tpma-report-chart-grid">
        <article class="tpma-report-card tpma-report-card-wide">
          <h2>財務總覽圖表</h2>
          <div class="tpma-chart-box"><canvas id="tpma-chart-finance-overview"></canvas></div>
          <div class="tpma-report-table-wrap"><table id="tpma-table-finance-overview"></table></div>
        </article>
      </div>
    </section>

    <section class="tpma-report-panel" data-report-panel="comparisons" role="tabpanel">
      <div class="tpma-report-chart-grid">
        <article class="tpma-report-card">
          <h2>年比較</h2>
          <div class="tpma-chart-box"><canvas id="tpma-chart-year-comparison"></canvas></div>
          <div class="tpma-report-table-wrap"><table id="tpma-table-year-comparison"></table></div>
        </article>
        <article class="tpma-report-card">
          <h2>月比較</h2>
          <div class="tpma-chart-box"><canvas id="tpma-chart-month-comparison"></canvas></div>
          <div class="tpma-report-table-wrap"><table id="tpma-table-month-comparison"></table></div>
        </article>
        <article class="tpma-report-card tpma-report-card-wide">
          <h2>日期區間比較</h2>
          <div class="tpma-chart-box"><canvas id="tpma-chart-period-comparison"></canvas></div>
          <div class="tpma-report-table-wrap"><table id="tpma-table-period-comparison"></table></div>
        </article>
      </div>
    </section>
  </div>
</div>
