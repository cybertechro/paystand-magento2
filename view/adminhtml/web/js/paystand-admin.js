/*browser:true*/
/*global define*/
define([
    'jquery', 
    'uiComponent',
], function ($, Component) {
    'use strict';

    jQuery(document).ready(function(){

        console.log("price",$(".order-tables > .price").html());
        //Custom js here

    });

    return Component.extend({
        // this function is binded to Magento's "Pay with Paystand" button
        init: function () {
            alert('init');
        },    
    
    });

});