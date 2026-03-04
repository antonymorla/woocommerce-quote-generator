jQuery(document).ready(function($) {
    $('#generate-quote').on('click', function() {
        $('body').append(`
            <div id="quote-form-overlay">
                <div id="quote-form">
                    <h2>Générer un devis</h2>
                    <label for="quote-name">Nom:</label>
                    <input type="text" id="quote-name" name="quote-name" required>
                    <label for="quote-surname">Prénom:</label>
                    <input type="text" id="quote-surname" name="quote-surname" required>
                    <label for="quote-address">Adresse:</label>
                    <textarea id="quote-address" name="quote-address" required></textarea>
                    <button id="submit-quote">Soumettre</button>
                    <button id="cancel-quote">Annuler</button>
                </div>
            </div>
        `);

        $('#cancel-quote').on('click', function() {
            $('#quote-form-overlay').remove();
        });

        $('#submit-quote').on('click', function() {
            var name = $('#quote-name').val();
            var surname = $('#quote-surname').val();
            var address = $('#quote-address').val();

            if (name && surname && address) {
                $.ajax({
                    url: wqg_params.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'wqg_generate_quote',
                        name: name,
                        surname: surname,
                        address: address
                    },
                    xhrFields: {
                        responseType: 'blob'
                    },
                    success: function(data) {
                        var link = document.createElement('a');
                        link.href = window.URL.createObjectURL(data);
                        link.download = 'devis.pdf';
                        link.click();
                        $('#quote-form-overlay').remove();
                    },
                    error: function(error) {
                        console.log(error);
                    }
                });
            } else {
                alert('Veuillez remplir tous les champs.');
            }
        });
    });
});