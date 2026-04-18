(function($){
    'use strict';
    $(document).ready(function(){
        $('.tt-dashboard').on('click', '.tt-tab', function(e){
            e.preventDefault();
            var tab = $(this).data('tab');
            var $root = $(this).closest('.tt-dashboard');
            $root.find('.tt-tab').removeClass('tt-tab-active');
            $(this).addClass('tt-tab-active');
            $root.find('.tt-tab-content').removeClass('tt-tab-content-active');
            $root.find('.tt-tab-content[data-tab="' + tab + '"]').addClass('tt-tab-content-active');
            if (history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tt_view', tab);
                history.replaceState({}, '', url);
            }
        });
        $('.tt-ajax-form').on('submit', function(e){
            e.preventDefault();
            var $form = $(this);
            var $msg = $form.find('.tt-form-msg').removeClass('tt-success tt-error').hide();
            var $btn = $form.find('button[type="submit"]').prop('disabled', true).text('Saving...');
            $.post(TT.ajax_url, $form.serialize())
                .done(function(res){
                    if (res.success) {
                        $msg.addClass('tt-success').text((res.data && res.data.message) || 'Saved.').show();
                        $form[0].reset();
                    } else {
                        $msg.addClass('tt-error').text((res.data && (res.data.message || res.data)) || 'Error.').show();
                    }
                })
                .fail(function(){ $msg.addClass('tt-error').text('Network error.').show(); })
                .always(function(){
                    var labels = { 'tt-eval-form': 'Save Evaluation', 'tt-session-form': 'Save Session', 'tt-goal-form': 'Add Goal' };
                    $btn.prop('disabled', false).text(labels[$form.attr('id')] || 'Save');
                });
        });
        $('.tt-dashboard').on('change', '.tt-goal-status-select', function(){
            $.post(TT.ajax_url, { action: 'tt_fe_update_goal_status', nonce: TT.nonce, goal_id: $(this).data('goal-id'), status: $(this).val() });
        });
        $('.tt-dashboard').on('click', '.tt-goal-delete', function(){
            if (!confirm('Delete this goal?')) return;
            var $btn = $(this);
            $.post(TT.ajax_url, { action: 'tt_fe_delete_goal', nonce: TT.nonce, goal_id: $btn.data('goal-id') })
                .done(function(res){ if (res.success) $btn.closest('tr').fadeOut(250, function(){ $(this).remove(); }); });
        });
    });
})(jQuery);
