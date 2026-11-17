define([
    'Magento_Checkout/js/view/summary/abstract-total',
    'Magento_Checkout/js/model/totals'
], function (Component, totals) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'MageOS_Loyalty/summary/total',
            code: 'loyalty',
            inCart: false // the cart page has no checkout steps, so no "full mode"
        },

        segment: function () {
            return totals.getSegment(this.code);
        },

        isDisplayed: function () {
            var segment = this.segment();

            return (this.inCart || this.isFullMode()) && !!segment && parseFloat(segment.value) !== 0;
        },

        getTitle: function () {
            return this.segment().title;
        },

        getValue: function () {
            return this.getFormattedPrice(this.segment().value);
        }
    });
});
