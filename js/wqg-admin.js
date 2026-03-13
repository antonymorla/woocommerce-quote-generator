/**
 * WooCommerce Quote Generator — gestion des lignes produits manuelles (admin)
 *
 * Nom des champs POST : wqg_manual[{idx}][description|qty|tva|price_ht]
 */
(function () {
    'use strict';

    var rowIndex  = 0;         // compteur pour les noms de champs
    var container = null;      // #wqg-manual-items
    var noItems   = null;      // #wqg-no-items

    // ----------------------------------------------------------------
    // Calcul du TTC unitaire et du total TTC pour une ligne
    // ----------------------------------------------------------------
    function calcTTC(row) {
        var priceHtInput = row.querySelector('.wqg-input-ht');
        var tvaSelect    = row.querySelector('.wqg-input-tva');
        var qtyInput     = row.querySelector('.wqg-input-qty');
        var ttcDisplay   = row.querySelector('.wqg-ttc-display');

        var ht  = parseFloat((priceHtInput.value || '0').replace(',', '.'));
        var tva = parseFloat(tvaSelect.value || '20');
        var qty = parseInt(qtyInput.value || '1', 10);

        if (isNaN(ht)) { ht  = 0; }
        if (isNaN(tva) || tva < 0) { tva = 20; }
        if (isNaN(qty) || qty === 0) { qty = 1; }

        var unitTva = ht * tva / 100;
        var unitTtc = ht + unitTva;
        var totalTtc = unitTtc * qty;

        var isNeg = totalTtc < 0;
        var color = isNeg ? '#c0392b' : '#7A94BF';
        var sign  = isNeg ? '- ' : '';
        ttcDisplay.innerHTML =
            '<span style="font-size:10px; color:' + color + ';">unit. ' + sign + fmt(Math.abs(unitTtc)) + ' €</span><br>' +
            '<strong style="color:' + (isNeg ? '#c0392b' : 'inherit') + ';">' + sign + fmt(Math.abs(totalTtc)) + ' €</strong>';
    }

    // ----------------------------------------------------------------
    // Formatage en nombre français (2 décimales)
    // ----------------------------------------------------------------
    function fmt(n) {
        return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // ----------------------------------------------------------------
    // Création d'une nouvelle ligne produit
    // ----------------------------------------------------------------
    function addRow() {
        var idx = rowIndex++;

        var row = document.createElement('div');
        row.className   = 'wqg-manual-row';
        row.dataset.idx = idx;

        row.innerHTML =
            '<button type="button" class="wqg-remove" title="Supprimer cette ligne">&#215;</button>' +
            '<div class="wqg-row-fields">' +

            /* Description */
            '<div class="wqg-field-desc">' +
                '<label>Description du produit</label>' +
                '<input type="text"' +
                       ' name="wqg_manual[' + idx + '][description]"' +
                       ' class="wqg-input-desc"' +
                       ' placeholder="Ex : Carport bois 3×5 m"' +
                       ' required>' +
            '</div>' +

            /* Quantité */
            '<div class="wqg-field-qty">' +
                '<label>Qté</label>' +
                '<input type="number"' +
                       ' name="wqg_manual[' + idx + '][qty]"' +
                       ' class="wqg-input-qty"' +
                       ' value="1" min="-9999" step="1">' +
            '</div>' +

            /* Taux TVA */
            '<div class="wqg-field-tva">' +
                '<label>TVA (%)</label>' +
                '<select name="wqg_manual[' + idx + '][tva]" class="wqg-input-tva">' +
                    '<option value="0">0 %</option>' +
                    '<option value="5.5">5,5 %</option>' +
                    '<option value="10">10 %</option>' +
                    '<option value="20" selected>20 %</option>' +
                '</select>' +
            '</div>' +

            /* Prix HT */
            '<div class="wqg-field-ht">' +
                '<label>Prix unit. HT (€)</label>' +
                '<input type="number"' +
                       ' name="wqg_manual[' + idx + '][price_ht]"' +
                       ' class="wqg-input-ht"' +
                       ' value="" min="-999999" step="0.01"' +
                       ' placeholder="0,00"' +
                       ' required>' +
            '</div>' +

            /* Total TTC calculé */
            '<div class="wqg-field-ttc">' +
                '<label>Total TTC (calculé)</label>' +
                '<div class="wqg-ttc-display">—</div>' +
            '</div>' +

            '</div>'; /* fin .wqg-row-fields */

        /* Événements de calcul */
        var inputs = row.querySelectorAll('.wqg-input-ht, .wqg-input-qty, .wqg-input-tva');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].addEventListener('input',  calcTTCHandler);
            inputs[i].addEventListener('change', calcTTCHandler);
        }

        /* Bouton supprimer */
        row.querySelector('.wqg-remove').addEventListener('click', function () {
            container.removeChild(row);
            updateNoItemsVisibility();
        });

        container.appendChild(row);
        updateNoItemsVisibility();

        /* Focus sur la description */
        row.querySelector('.wqg-input-desc').focus();
    }

    function calcTTCHandler() {
        var row = this.closest('.wqg-manual-row');
        if (row) { calcTTC(row); }
    }

    // ----------------------------------------------------------------
    // Affiche / cache le message "aucun produit"
    // ----------------------------------------------------------------
    function updateNoItemsVisibility() {
        var rows = container.querySelectorAll('.wqg-manual-row');
        noItems.style.display = rows.length === 0 ? '' : 'none';
    }

    // ----------------------------------------------------------------
    // Initialisation au chargement de la page
    // ----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        container = document.getElementById('wqg-manual-items');
        noItems   = document.getElementById('wqg-no-items');
        var addBtn = document.getElementById('wqg-add-item');

        if (!container || !noItems || !addBtn) { return; }

        addBtn.addEventListener('click', addRow);
    });

}());
