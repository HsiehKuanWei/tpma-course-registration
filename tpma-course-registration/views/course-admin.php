<?php
if (!defined('ABSPATH')) { exit; }

$apiBase   = esc_url_raw( untrailingslashit( rest_url('tpma/v1') ) );
$restNonce = wp_create_nonce( 'wp_rest' );
?>
<link rel="stylesheet" href="<?php echo esc_url( TPMA_CR_URL . 'assets/css/course-admin.css?ver=' . TPMA_CR_VERSION ); ?>">

<div id="tpma-course-admin" class="tpma-wrap">
    <div class="tpma-filter-row">
        <input type="text" id="tpma-filter-q" placeholder='‚-o‚?æ†--‹¬sŠ¦ý‡"<‡ú"ŠTY / Š¦ý‡"<†??‡"ñ / ‚­z†^ / Šª>†,®‹¬^†?3‘T,‡_c‚?,‹¬%'>

        <select id="tpma-filter-category">
            <option value="">†."‚Ÿ"‚­z†^</option>
            <optgroup label='‘ÿ,†¨ŸŠ¦ý‡"<"'>
                <option value="A1">Š`œ„§<‡s,‘3†_<‡_c†<TŠ^ØŠýª„¯¯</option>
                <option value="A2">Š`œ„§<‘oŸ‡s,‘z‘<Š^Ø‚?<„«o</option>
                <option value="A3">‘??†?ØŠ`œ„§<‘oŸ‡,_‘^</option>
                <option value="A4">Šý­†<Ta??‘oŸŠ"^</option>
                <option value="A5">‘ø,‡§O‡T¬†ñ</option>
            </optgroup>
            <optgroup label='†ø^‘-Š¦ý‡"<"'>
                <option value="B1">Š`œ„§<‘oŸ‘^?†"­†'O‡r­‡?+†o~‚sS„1<‚-"‡s,‚-o„¨,Š^Ø†?^„«o</option>
                <option value="B2">Š`œ„§<Š^ØŠ,­‘?ñ‘oŸ„§<†<T</option>
                <option value="B3">†.ª†?,‘%?†ñª‡"›‘-„1<‘-†<Ta??†+†<T</option>
                <option value="B4">‚›"‚s¦‡r­‡?+a??†.‚Ÿ"‘Z†^a??‘,„«?‘ý¯‡?+</option>
                <option value="B5">†.„¯-</option>
            </optgroup>
        </select>

        <select id="tpma-filter-lecturer">
            <option value="">†."‚Ÿ"Šª>†,®</option>
        </select>

        <select id="tpma-filter-course">
            <option value="">†."‚Ÿ"Š¦ý‡"<†??‡"ñ</option>
        </select>
    </div>

    <div class="tpma-filter-row">
        <span>‘Z^Š¦ý‘-‘oY‡_c‚?,‹¬s</span>
        <input type="date" id="tpma-filter-date-from">
        <span>‹«z</span>
        <input type="date" id="tpma-filter-date-to">

        <select id="tpma-filter-mode">
            <option value="open_only">†."‚Ÿ"‹¬^„,?†?®†?oŠ¦ý‹¬%</option>
            <option value="with_closed">†."‚Ÿ"‹¬^†?®†?oŠ¦ý‹¬%</option>
            <option value="scheduled_future">†úý†r%‘Z'†ÿ'‘ª­‹¬^‘o%‘o¦„_+‘-‘oY‹¬%</option>
        </select>

        <span style="font-size:12px;color:#666;">
            „,?‚?,‘-‘oY‘T,„_?‘"­†¬?‚­_‡§Š¦ý‡"<‹¬s‚ÿ?Š"-†Ÿ.†^-†Ø§‚-<Š¦ý„,-‹¬O†?_†^Ø‘?>†?®†?oŠ¦ý‘^-†Ÿ.‘o%‘o¦„_+†ÿ'‘ª­a?,
        </span>
    </div>

    <div class="tpma-filter-row">
        <button class="tpma-btn" id="tpma-add-course">‘-ø†›zŠ¦ý‡"<</button>
        <button class="tpma-btn" id="tpma-reset-filter">‚Ø?‡«r‡_c‚?,</button>
    </div>

    <div id="tpma-course-list">
        <p>Š¬%†.„,-...</p>
    </div>
</div>

<!-- ‘-ø†›zŠª>†,® Modal -->
<div id="tpma-lecturer-backdrop" class="tpma-modal-backdrop"></div>
<div id="tpma-lecturer-modal" class="tpma-modal">
    <h3>‘-ø†›zŠª>†,®</h3>
    <label>Šª>†,®„¯œ‡›¬<span class="tpma-required-label">†¨.†­®</span></label>
    <input type="text" id="tpma-lect-code" placeholder='„_<‹¬sHSSA'>
    <label>Šª>†,®†"†??<span class="tpma-required-label">†¨.†­®</span></label>
    <input type="text" id="tpma-lect-name" placeholder='Šª>†,®†"†??'>
    <label>Šª>†,®‡"ñŠª,</label>
    <input type="text" id="tpma-lect-title" placeholder='„_<‹¬s†_<†,® / ‘T‘Z^'>
    <label>Šª>†,®‘Z'†§?‹¬^‘,†--‹¬O†?_‡T‡c§ŠØ¦†<†,†.‹¬%</label>
    <input type="number" id="tpma-lect-sort" placeholder='„_<‹¬s10'>

    <div class="tpma-error" id="tpma-lect-error" style="display:none;"></div>

    <div class="tpma-modal-actions">
        <button type="button" class="tpma-btn" id="tpma-lect-cancel-btn">†?-‘^</button>
        <button type="button" class="tpma-btn" id="tpma-lect-save-btn">†,ý†-~Šª>†,®</button>
    </div>
</div>

<script>
window.TPMACourseAdminConfig = <?php echo wp_json_encode(array(
    'apiBase' => $apiBase,
    'nonce'   => $restNonce,
)); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/01.course-admin.base.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/02.course-admin.render.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/03.course-admin.logic.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
<script src="<?php echo esc_url( TPMA_CR_URL . 'assets/js/course-admin/04.course-admin.init.js?ver=' . TPMA_CR_VERSION ); ?>"></script>
