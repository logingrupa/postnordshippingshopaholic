/**
 * PostNord Service Point Locator
 *
 * Vanilla JS (ES6+) with Larajax (and legacy oc.request fallback).
 * Uses document-level event delegation so it works when injected via AJAX.
 *
 * @see PostNordLocator component (components/PostNordLocator.php)
 */
(function () {
    'use strict';

    /** @type {number|null} */
    let iDebounceTimeout = null;

    /** @type {number} Debounce delay in milliseconds */
    const DEBOUNCE_DELAY = 400;

    /** @type {number} Minimum postal code length to trigger search */
    const MIN_POSTAL_LENGTH = 4;

    document.addEventListener('input', function (obEvent) {
        const obTarget = obEvent.target;
        if (!(obTarget instanceof HTMLInputElement) || !obTarget.matches('[data-postnord-postal-input]')) {
            return;
        }

        const obContainer = obTarget.closest('[data-postnord-locator]');
        if (!(obContainer instanceof HTMLElement)) {
            return;
        }

        const sPostalCode = obTarget.value.trim();

        if (iDebounceTimeout !== null) {
            clearTimeout(iDebounceTimeout);
        }

        if (sPostalCode.length < MIN_POSTAL_LENGTH) {
            return;
        }

        const sShippingTypeId = obContainer.dataset.shippingTypeId || '';

        iDebounceTimeout = window.setTimeout(function () {
            fetchPostNordPoints(sPostalCode, sShippingTypeId);
        }, DEBOUNCE_DELAY);
    });

    document.addEventListener('change', function (obEvent) {
        const obTarget = obEvent.target;
        if (!(obTarget instanceof HTMLInputElement) || !obTarget.matches('[data-postnord-point]')) {
            return;
        }

        selectServicePoint(obTarget);
    });

    /**
     * Execute a Larajax (jax) or legacy October (oc.request) call.
     *
     * @param {string} sHandler
     * @param {Record<string, unknown>} obOptions
     * @returns {void}
     */
    function runAjax(sHandler, obOptions) {
        if (typeof window.jax !== 'undefined' && typeof window.jax.ajax === 'function') {
            window.jax.ajax(sHandler, obOptions);
            return;
        }

        if (typeof window.oc !== 'undefined' && typeof window.oc.request === 'function') {
            window.oc.request('#postnord-locator', sHandler, obOptions);
        }
    }

    /**
     * Fetch PostNord service points via Larajax.
     *
     * @param {string} sPostalCode
     * @param {string} sShippingTypeId
     * @returns {void}
     */
    function fetchPostNordPoints(sPostalCode, sShippingTypeId) {
        /** @type {Record<string, string>} */
        const obData = { postal_code: sPostalCode };
        if (sShippingTypeId !== '') {
            obData.shipping_type_id = sShippingTypeId;
        }

        runAjax('PostNordLocator::onGetServicePoints', {
            data: obData
        });
    }

    /**
     * Save selected service point to session via Larajax.
     *
     * @param {HTMLInputElement} obRadio
     * @returns {void}
     */
    function selectServicePoint(obRadio) {
        runAjax('PostNordLocator::onSelectServicePoint', {
            data: {
                service_point_id: obRadio.dataset.pointId || '',
                service_point_name: obRadio.dataset.pointName || '',
                service_point_address: obRadio.dataset.pointAddress || ''
            }
        });
    }
})();
