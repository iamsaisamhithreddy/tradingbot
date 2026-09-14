<script>
    Chart.defaults.font.family = "'Inter', 'sans-serif'";
    Chart.defaults.color = '#64748b';

    const ctxEquity = document.getElementById('equityChart').getContext('2d');
    new Chart(ctxEquity, {
        type: 'line',
        data: {
            labels: <?= json_encode($equity_labels) ?>,
            datasets: [{
                label: 'Net Balance (₹)',
                data: <?= json_encode($equity_data) ?>,
                borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.15)',
                borderWidth: 2, fill: true, tension: 0.1, pointRadius: 0
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    const ctxWinLoss = document.getElementById('winLossChart').getContext('2d');
    new Chart(ctxWinLoss, {
        type: 'doughnut',
        data: {
            labels: ['Wins', 'Losses', 'Ties'],
            datasets: [{ data: [<?= $wins ?>, <?= $losses ?>, <?= $ties ?>], backgroundColor: ['#10b981', '#f43f5e', '#cbd5e1'], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom' } } }
    });

    function filterTable() {
        const input = document.getElementById("tableSearch");
        const filter = input.value.toLowerCase();
        const tr = document.getElementById("tradeTable").getElementsByTagName("tr");
        for (let i = 1; i < tr.length; i++) {
            let rowText = tr[i].innerText.toLowerCase();
            let inputs = tr[i].getElementsByTagName("input");
            if(inputs.length > 0) rowText += " " + inputs[0].value.toLowerCase();
            tr[i].style.display = rowText.indexOf(filter) > -1 ? "" : "none";
        }
    }
</script>