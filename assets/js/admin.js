document.addEventListener("DOMContentLoaded", function () {

    document.querySelectorAll(".wc-pvr-manual-send button[data-confirm]").forEach(function (button) {
        button.form.addEventListener("submit", function (event) {
            if (!window.confirm(button.getAttribute("data-confirm"))) {
                event.preventDefault();
                return;
            }
            const confirmationInput = button.form.querySelector('input[name="confirmed"]');
            if (confirmationInput) { confirmationInput.value = "1"; }
            button.disabled = true;
            button.textContent = button.getAttribute("data-sending-label") || "Sending…";
        });
    });

    if (typeof wc_pvr_data === "undefined") {
        return;
    }

    let remaining = parseInt(wc_pvr_data.remaining, 10);
    if (isNaN(remaining)) {
        remaining = 0;
    }

    let countdownEl = document.getElementById("wc-pvr-countdown");

    if (!countdownEl) { return; }

    const dueNow = (wc_pvr_data.i18n && wc_pvr_data.i18n.dueNow) || "A check is due now. Refresh to see the next one.";
    const nextCheck = (wc_pvr_data.i18n && wc_pvr_data.i18n.nextCheck) || "Next check in: %m minutes %s seconds";

    function updateCountdown() {

        if (remaining <= 0) {
            countdownEl.textContent = dueNow;
            return;
        }

        let minutes = Math.floor(remaining / 60);
        let seconds = remaining % 60;

        countdownEl.textContent = nextCheck
            .replace("%m", String(minutes))
            .replace("%s", String(seconds));

        remaining--;
    }

    updateCountdown();

    setInterval(updateCountdown, 1000);

});
