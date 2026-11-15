jQuery(document).ready(function ($) {

    setTimeout(function () {

        $.post(
            wc_pvr_data.ajax_url,
            {
                action: 'wc_pvr_track',
                product_id: wc_pvr_data.product_id,
                nonce: wc_pvr_data.nonce
            }
        );

    }, wc_pvr_data.delay * 1000);

});