document.addEventListener("DOMContentLoaded", function () {

    if (typeof wc_pvr_data === "undefined") {
        return;
    }

    let remaining = parseInt(wc_pvr_data.remaining);

    let countdownEl = document.getElementById("wc-pvr-countdown");

    if (!countdownEl) {
        return;
    }

    function updateCountdown() {

        if (remaining <= 0) {

            countdownEl.innerHTML =
                "Cron should run now (refresh page to see next schedule)";

            return;
        }

        let minutes = Math.floor(remaining / 60);
        let seconds = remaining % 60;

        countdownEl.innerHTML =
            "Cron will run in: " + minutes + " minutes " + seconds + " seconds";

        remaining--;
    }

    updateCountdown();

    setInterval(updateCountdown, 1000);

});


jQuery(function ($) {

    function toggleCartMessage() {

        if ($('input[name="wc_pvr_cart_reminder"]').is(':checked')) {

            $('#wc-pvr-cart-message-row').show();

        } else {

            $('#wc-pvr-cart-message-row').hide();

        }

    }

    toggleCartMessage();

    $('input[name="wc_pvr_cart_reminder"]').on('change', function () {

        toggleCartMessage();

    });

});