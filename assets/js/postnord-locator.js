/**
 * PostNord Service Point Locator
 *
 * Vanilla JS (ES6+) with Larajax for AJAX requests.
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

    /**
     * Initialize the PostNord locator when DOM is ready.
     */
    document.addEventListener('DOMContentLoaded', function () {
        /** @type {HTMLElement|null} */
        const obContainer = document.querySelector('[data-postnord-locator]');

        if (!obContainer) {
            return;
        }

        /** @type {HTMLInputElement|null} */
        const obPostalInput = obContainer.querySelector('[data-postnord-postal-input]');

        if (!obPostalInput) {
            return;
        }

        obPostalInput.addEventListener('input', function () {
            /** @type {string} */
            const sPostalCode = obPostalInput.value.trim();

            if (iDebounceTimeout) {
                clearTimeout(iDebounceTimeout);
            }

            if (sPostalCode.length < MIN_POSTAL_LENGTH) {
                return;
            }

            iDebounceTimeout = setTimeout(function () {
                fetchPostNordPoints(sPostalCode);
            }, DEBOUNCE_DELAY);
        });

        obContainer.addEventListener('change', function (obEvent) {
            /** @type {HTMLInputElement} */
            const obTarget = obEvent.target;

            if (!obTarget.matches('[data-postnord-point]')) {
                return;
            }

            selectServicePoint(obTarget);
        });
    });

    /**
     * Fetch PostNord service points via Larajax.
     *
     * @param {string} sPostalCode The user-entered postal code
     * @returns {void}
     */
    function fetchPostNordPoints(sPostalCode) {
        if (typeof oc === 'undefined' || typeof oc.request === 'undefined') {
            return;
        }

        oc.request('#postnord-locator', 'PostNordLocator::onGetServicePoints', {
            data: { postal_code: sPostalCode },
            update: { 'PostNordLocator::service-points': '#postnord-service-points' }
        });
    }

    /**
     * Save selected service point to session via Larajax.
     *
     * @param {HTMLInputElement} obRadio The selected radio button element
     * @returns {void}
     */
    function selectServicePoint(obRadio) {
        /** @type {string} */
        const sPointId = obRadio.dataset.pointId || '';
        /** @type {string} */
        const sPointName = obRadio.dataset.pointName || '';
        /** @type {string} */
        const sPointAddress = obRadio.dataset.pointAddress || '';

        if (typeof oc === 'undefined' || typeof oc.request === 'undefined') {
            return;
        }

        oc.request('#postnord-locator', 'PostNordLocator::onSelectServicePoint', {
            data: {
                service_point_id: sPointId,
                service_point_name: sPointName,
                service_point_address: sPointAddress
            }
        });
    }
})();
