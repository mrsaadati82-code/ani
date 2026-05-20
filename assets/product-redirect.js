jQuery(function ($) {
  if (!window.FCUI_REDIRECT) return;

  var fastBase = FCUI_REDIRECT.fast_url;

  function requireLoginRedirect() {
    if (FCUI_REDIRECT.require_login && !FCUI_REDIRECT.is_logged_in) {
      window.location = FCUI_REDIRECT.login_url;
      return true;
    }
    return false;
  }

  function isFastUrl(href) {
    if (!href) return false;
    return href.indexOf(fastBase) === 0;
  }

  // Convert theme links like ?add-to-cart=XXXX to fast-checkout
  $('a[href*="add-to-cart="]').each(function () {
    try {
      var href = $(this).attr('href');
      var url = new URL(href, window.location.origin);
      var pid = url.searchParams.get('add-to-cart');
      if (!pid) return;

      var newUrl = new URL(fastBase);
      newUrl.searchParams.set('product_id', pid);

      $(this).attr('href', newUrl.toString());

      // Remove ajax add-to-cart behavior (common in themes)
      $(this)
        .removeClass('ajax_add_to_cart add_to_cart_button')
        .removeAttr('data-product_id data-product_sku data-quantity aria-label rel');
    } catch (e) {}
  });

  // Force navigation even if other scripts preventDefault (Capture phase)
  document.addEventListener(
    'click',
    function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a') : null;
      if (!a) return;
      if (!isFastUrl(a.href)) return;

      if (requireLoginRedirect()) {
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();
      window.location.href = a.href;
    },
    true
  );

  // Variable products: redirect on form submit with variation data
  $(document).on('submit', 'form.cart', function (e) {
    if (FCUI_REDIRECT.require_login && !FCUI_REDIRECT.is_logged_in) {
      e.preventDefault();
      window.location = FCUI_REDIRECT.login_url;
      return;
    }

    var $form = $(this);
    var isVariable = $form.hasClass('variations_form');
    var variationId = parseInt($form.find('input[name=variation_id]').val() || 0, 10);

    // Let Woo show "choose options" message if not selected
    if (isVariable && (!variationId || variationId === 0)) return;

    e.preventDefault();
    e.stopImmediatePropagation();

    var productId = parseInt(
      $form.find('input[name=product_id]').val() ||
        $form.find('input[name=add-to-cart]').val() ||
        0,
      10
    );
    var qty = parseInt($form.find('input.qty').val() || 1, 10);

    var url = new URL(fastBase);
    url.searchParams.set('product_id', productId);
    url.searchParams.set('quantity', qty);
    if (variationId) url.searchParams.set('variation_id', variationId);

    // add attributes
    $form.find('select[name^=attribute_], input[name^=attribute_]').each(function () {
      var name = $(this).attr('name');
      var val = $(this).val();
      if (name && val) url.searchParams.set(name, val);
    });

    window.location.href = url.toString();
  });
});