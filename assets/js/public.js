(function($){
    'use strict';

    // Default labels if localization fails for any reason
    var i18n = (window.TT && TT.i18n) ? TT.i18n : {
        saving: 'Saving...',
        saved: 'Saved.',
        error_generic: 'Error.',
        network_error: 'Network error.',
        confirm_delete_goal: 'Delete this goal?',
        save_evaluation: 'Save Evaluation',
        save_session: 'Save Session',
        add_goal: 'Add Goal',
        save: 'Save'
    };

    $(document).ready(function(){

        // Tab switching
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

        // AJAX form submission
        $('.tt-ajax-form').on('submit', function(e){
            e.preventDefault();
            var $form = $(this);
            var $msg = $form.find('.tt-form-msg').removeClass('tt-success tt-error').hide();
            var $btn = $form.find('button[type="submit"]').prop('disabled', true).text(i18n.saving);

            $.post(TT.ajax_url, $form.serialize())
                .done(function(res){
                    if (res.success) {
                        $msg.addClass('tt-success').text((res.data && res.data.message) || i18n.saved).show();
                        $form[0].reset();
                    } else {
                        $msg.addClass('tt-error').text((res.data && (res.data.message || res.data)) || i18n.error_generic).show();
                    }
                })
                .fail(function(){ $msg.addClass('tt-error').text(i18n.network_error).show(); })
                .always(function(){
                    var labels = {
                        'tt-eval-form':    i18n.save_evaluation,
                        'tt-session-form': i18n.save_session,
                        'tt-goal-form':    i18n.add_goal
                    };
                    $btn.prop('disabled', false).text(labels[$form.attr('id')] || i18n.save);
                });
        });

        // Goal status inline update
        $('.tt-dashboard').on('change', '.tt-goal-status-select', function(){
            $.post(TT.ajax_url, {
                action: 'tt_fe_update_goal_status',
                nonce: TT.nonce,
                goal_id: $(this).data('goal-id'),
                status: $(this).val()
            });
        });

        // Goal delete
        $('.tt-dashboard').on('click', '.tt-goal-delete', function(){
            if (!confirm(i18n.confirm_delete_goal)) return;
            var $btn = $(this);
            $.post(TT.ajax_url, {
                action: 'tt_fe_delete_goal',
                nonce: TT.nonce,
                goal_id: $btn.data('goal-id')
            }).done(function(res){
                if (res.success) $btn.closest('tr').fadeOut(250, function(){ $(this).remove(); });
            });
        });
    });
})(jQuery);
