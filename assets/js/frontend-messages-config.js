/* #3112 — Configuration → Messages.
 *
 * Two independent decisions on one form, each synced into its own hidden
 * JSON field so the standard config-form submit handler ships one value
 * per key:
 *
 *   comms_templates_disabled        — the UNticked messages
 *   comms_template_channels_blocked — the UNticked channels, per message
 *
 * Both stored sets are the negative ones, matching TemplateSwitch and
 * TemplateChannels: an empty value means "nothing changed", so anything
 * that ships in a later release lands in a defined state.
 *
 * Unticking every channel of a message is not a way to switch it off —
 * the server drops such an entry, because a message with nowhere to go
 * would be recorded as a failure rather than as a decision. The last
 * ticked channel of a message is therefore held, and the form says why.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-tt-messages-form]');
    if (!form) return;

    var disabledField = form.querySelector('[data-tt-messages-json]');
    var channelsField = form.querySelector('[data-tt-messages-channels-json]');
    var messageBoxes  = Array.prototype.slice.call(form.querySelectorAll('[data-tt-message-template]'));
    var channelBoxes  = Array.prototype.slice.call(form.querySelectorAll('[data-tt-message-channel-of]'));

    function channelsOf(templateKey) {
        return channelBoxes.filter(function (box) {
            return box.getAttribute('data-tt-message-channel-of') === templateKey;
        });
    }

    function sync() {
        var off = [];
        messageBoxes.forEach(function (box) {
            if (!box.checked) off.push(box.getAttribute('data-tt-message-template'));
        });
        disabledField.value = JSON.stringify(off);

        var blocked = {};
        channelBoxes.forEach(function (box) {
            if (box.checked) return;
            var key = box.getAttribute('data-tt-message-channel-of');
            if (!blocked[key]) blocked[key] = [];
            blocked[key].push(box.getAttribute('data-tt-message-channel'));
        });
        channelsField.value = JSON.stringify(blocked);
    }

    /* Hold the last ticked channel rather than letting the save silently
     * discard the whole entry — the person would come back to a row that
     * looks untouched and never learn why. */
    function guardLastChannel(box) {
        if (box.checked) return;
        var siblings = channelsOf(box.getAttribute('data-tt-message-channel-of'));
        var stillOn = siblings.some(function (other) { return other.checked; });
        if (!stillOn) box.checked = true;
    }

    /* A switched-off message's channel controls are irrelevant until it
     * is switched back on. Disabling them keeps the row honest without
     * losing what was ticked. */
    function reflectMessageState(box) {
        var enabled = box.checked;
        channelsOf(box.getAttribute('data-tt-message-template')).forEach(function (channel) {
            channel.disabled = !enabled;
        });
        var row = box.closest ? box.closest('.tt-messages-row') : null;
        if (row) row.classList.toggle('tt-messages-row--off', !enabled);
    }

    messageBoxes.forEach(function (box) {
        box.addEventListener('change', function () {
            reflectMessageState(box);
            sync();
        });
        reflectMessageState(box);
    });

    channelBoxes.forEach(function (box) {
        box.addEventListener('change', function () {
            guardLastChannel(box);
            sync();
        });
    });

    sync();
})();
