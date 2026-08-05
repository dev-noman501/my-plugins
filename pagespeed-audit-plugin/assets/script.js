jQuery(document).ready(function ($) {

    let auditResults = {};

    // Show popup if email not set
    if (!localStorage.getItem('psa_email_set')) {
        $('#email-popup').show();
        $('#psa-form').hide();
    }

    // Handle email submission
    $('#submit-email').on('click', function () {
        const email = $('#user-email').val().trim();
        if (!email || !email.includes('@')) {
            alert("Please enter a valid email.");
            return;
        }

        $.post(psa_ajax.ajax_url, { action: 'psa_save_email', email: email }, function (response) {
            if (response.success) {
                localStorage.setItem('psa_email_set', 'true');
                $('#email-popup').hide();
                $('#psa-form').show();
            } else {
                alert(response.data);
            }
        });
    });

    // Handle audit form submit
    $('#psa-form').on('submit', function (e) {
        e.preventDefault();
        const url = $('#psa-url').val().trim();
        if (!url) {
            alert("Please enter a valid URL.");
            return;
        }

        $('#loading').show();
        $('#psa-result').empty();
        $('#view-tabs').hide();

        $.post(psa_ajax.ajax_url, { action: 'psa_run_audit', url: url }, function (response) {
            $('#loading').hide();
            if (response.success) {
                auditResults = response.data;

                // Build view tabs dynamically with icons
                $('#view-tabs').html(`
                    <button class="view-tab active" data-view="desktop">
                     Desktop
                    </button>
                    <button class="view-tab" data-view="mobile">
                     Mobile
                    </button>
                `);

                $('#view-tabs').show();
                renderResults('desktop');
            } else {
                $('#psa-result').html("❌ Error: " + response.data);
            }
        });
    });


    // Switch between Desktop / Mobile
    $(document).on('click', '.view-tab', function () {
        $('.view-tab').removeClass('active');
        $(this).addClass('active');
        renderResults($(this).data('view'));
    });

    // Render results for selected view
    function renderResults(view) {
        let html = '<div class="scores-container">';
        $.each(auditResults[view].scores, function (key, value) {
            let scoreClass = getScoreColorClass(value);
            html += `
                <div class="score-item">
                    <div class="score-circle ${scoreClass}">${value}</div>
                    <div class="score-name">${key}</div>
                </div>
            `;
        });
        html += '</div>';

        // Core Web Vitals section (table style)
        html += `<div class="vitals-container">
                    <h3>Core Web Vitals</h3>
                    <table class="vitals-table"><tbody>`;
        $.each(auditResults[view].metrics, function (metric, val) {
            let metricClass = getMetricColorClass(metric, val);
            html += `
                <tr>
                    <td class="vital-name">${metric}</td>
                    <td class="vital-value ${metricClass}">${val}</td>
                </tr>
            `;
        });
        html += `</tbody></table></div>`;

        $('#psa-result').html(html);
    }

    // Color classes for score circles
    function getScoreColorClass(score) {
        if (score >= 90) return 'green';
        if (score >= 50) return 'yellow';
        return 'red';
    }

    // Color classes for Core Web Vitals table values
    function getMetricColorClass(metric, value) {
        if (!value || value === 'N/A') return '';
        let num = parseFloat(value);
        if (metric.includes('First Contentful Paint')) {
            if (num <= 1.8) return 'green';
            if (num <= 3.0) return 'yellow';
            return 'red';
        }
        if (metric.includes('Largest Contentful Paint')) {
            if (num <= 2.5) return 'green';
            if (num <= 4.0) return 'yellow';
            return 'red';
        }
        if (metric.includes('Total Blocking Time')) {
            if (num <= 200) return 'green';
            if (num <= 600) return 'yellow';
            return 'red';
        }
        if (metric.includes('Cumulative Layout Shift')) {
            if (num <= 0.1) return 'green';
            if (num <= 0.25) return 'yellow';
            return 'red';
        }
        if (metric.includes('Speed Index')) {
            if (num <= 3.4) return 'green';
            if (num <= 5.8) return 'yellow';
            return 'red';
        }
        return '';
    }

});
