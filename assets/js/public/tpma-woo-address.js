(function($){
  'use strict';

  function normZip(v){
    v = (v || '').toString().trim().replace(/\D+/g,'');
    // 台灣常用 3 碼，也有人輸 5 碼；這裡先用前 3 碼做對照
    if (v.length >= 3) return v.substring(0,3);
    return v;
  }

  function buildIndex(data){
    var byZip = {};
    var counties = [];
    (data || []).forEach(function(c){
      if (!c || !c.name) return;
      counties.push(c.name);
      (c.districts || []).forEach(function(d){
        if (!d || !d.zip || !d.name) return;
        byZip[String(d.zip)] = { county: c.name, district: d.name, zip: String(d.zip) };
      });
    });
    return { byZip: byZip, counties: counties };
  }

  function findZipFor(county, district, data){
    var zip = '';
    (data || []).some(function(c){
      if (c.name !== county) return false;
      return (c.districts || []).some(function(d){
        if (d.name === district) { zip = String(d.zip); return true; }
        return false;
      });
    });
    return zip;
  }

  function getDistrictsOf(county, data){
    var list = [];
    (data || []).some(function(c){
      if (c.name !== county) return false;
      list = (c.districts || []).map(function(d){ return d.name; });
      return true;
    });
    return list;
  }

  function setSelectOptions($sel, placeholder, options, selected){
    $sel.empty();
    $sel.append($('<option/>').attr('value','').text(placeholder || '請選擇'));
    (options || []).forEach(function(v){
      $sel.append($('<option/>').attr('value', v).text(v));
    });
    if (selected != null) $sel.val(selected);
  }

  function enhanceSelect($sel){
    // Woo 目前多用 selectWoo（select2 的 wrapper）
    // 只要有載入，class 'wc-enhanced-select' 就會自動強化；這裡補一次保險
    if ($.fn.selectWoo) {
      try { $sel.selectWoo({ width: '100%' }); } catch(e){}
    } else if ($.fn.select2) {
      try { $sel.select2({ width: '100%' }); } catch(e){}
    }
  }

  $(function(){
    if (!window.TPMA_WOO_ADDR) return;

    var data = TPMA_WOO_ADDR.districts || [];
    var idx  = buildIndex(data);

    var selZip   = (TPMA_WOO_ADDR.selectors && TPMA_WOO_ADDR.selectors.zip)   || '#tpma_postcode';
    var selState = (TPMA_WOO_ADDR.selectors && TPMA_WOO_ADDR.selectors.state) || '#tpma_state';
    var selCity  = (TPMA_WOO_ADDR.selectors && TPMA_WOO_ADDR.selectors.city)  || '#tpma_city';

    var $zip   = $(selZip);
    var $state = $(selState);
    var $city  = $(selCity);

    if (!$zip.length || !$state.length || !$city.length) return;

    // 初始化：縣市下拉
    setSelectOptions($state, '請選擇縣市', idx.counties, $state.val() || '');
    enhanceSelect($state);
    enhanceSelect($city);

    function refreshDistricts(selectedDistrict){
      var county = $state.val();
      if (!county) {
        setSelectOptions($city, '請先選擇縣市', [], '');
        $city.prop('disabled', true).trigger('change');
        return;
      }
      var districts = getDistrictsOf(county, data);
      setSelectOptions($city, '請選擇行政區', districts, selectedDistrict || '');
      $city.prop('disabled', false).trigger('change');
      enhanceSelect($city);
    }

    // 事件：選縣市 → 限定行政區
    $state.on('change', function(){
      refreshDistricts('');
      // 若使用者先選縣市/行政區但沒填 zip，後續在選行政區時會自動補
      if (!$zip.val()) $zip.val('');
    });

    // 事件：選行政區 → 若 zip 空白自動帶入
    $city.on('change', function(){
      var county = $state.val();
      var dist   = $city.val();
      if (!county || !dist) return;

      if (!$zip.val()) {
        var z = findZipFor(county, dist, data);
        if (z) $zip.val(z);
      }
    });

    // 事件：輸入郵遞區號 → 自動帶入縣市/行政區
    function syncFromZip(){
      var z = normZip($zip.val());
      if (!z) return;

      var hit = idx.byZip[String(z)];
      if (!hit) return;

      // 設定縣市
      $state.val(hit.county).trigger('change');

      // 重新灌行政區後，設行政區
      refreshDistricts(hit.district);
      $city.val(hit.district).trigger('change');

      // zip 若輸入 5 碼也不影響，回寫為 3 碼（資料表對照用）
      $zip.val(hit.zip);
    }

    $zip.on('change blur', syncFromZip);

    // 若頁面初始已有 zip（例如返回結帳頁）
    syncFromZip();
  });

})(jQuery);
