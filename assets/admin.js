(function ($) {
  function renumber() {
    const $rows = $('#mka-wd-wrapper .mka-wd-row');
    $rows.each(function (idx) {
      $(this).find('input').each(function () {
        const name = $(this).attr('name');
        if (!name) return;
        $(this).attr('name', name.replace(/\[\d+\]/, '[' + idx + ']'));
      });
    });
  }

  $(document).on('click', '#mka-wd-add', function () {
    const $wrapper = $('#mka-wd-wrapper');
    const $rows = $wrapper.find('.mka-wd-row');
    const $template = $rows.first().clone();

    $template.find('input').val('');
    $wrapper.append($template);

    renumber();
  });

  $(document).on('click', '.mka-wd-remove', function () {
    const $wrapper = $('#mka-wd-wrapper');
    const $rows = $wrapper.find('.mka-wd-row');

    if ($rows.length <= 1) {
      $rows.first().find('input').val('');
      return;
    }

    $(this).closest('.mka-wd-row').remove();
    renumber();
  });
})(jQuery);
