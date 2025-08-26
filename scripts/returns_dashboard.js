// Returns Dashboard Logic
let returnsTable;
let returnsChart;

async function fetchSummary() {
    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=summary`);
        const data = await res.json();
        if (data.summary) {
            document.getElementById('stat-in-progress').textContent = data.summary.in_progress;
            document.getElementById('stat-pending').textContent = data.summary.pending;
            document.getElementById('stat-completed').textContent = data.summary.completed;
            document.getElementById('stat-discrepancies').textContent = data.summary.discrepancies;
        }
    } catch (err) {
        console.error('Summary error', err);
    }
}

function initTable() {
    returnsTable = $('#returns-table').DataTable({
        ajax: {
            url: `${WMS_CONFIG.apiBase}/returns/admin.php?action=list`,
            dataSrc: function(json){ return json.returns || []; }
        },
        columns: [
            { data: 'id' },
            { data: 'order_number' },
            { data: 'customer_name' },
            { data: 'status' },
            { data: 'processed_by' },
            { data: 'created_at' },
            { data: 'verified_at' },
            { data: 'discrepancies' }
        ]
    });

    $('#returns-table tbody').on('click', 'tr', function(){
        const data = returnsTable.row(this).data();
        if (data) {
            loadReturnDetail(data.id);
        }
    });
}

async function loadReturnDetail(id) {
    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=detail&id=${id}`);
        const data = await res.json();
        if (!data.success) return;
        const r = data.return;
        let html = `<h3>Return #${r.id} - ${r.order_number}</h3>`;
        html += `<p>Status: ${r.status}</p>`;
        html += `<p>Client: ${r.customer_name}</p>`;
        html += '<h4>Produse</h4><ul>';
        data.items.forEach(i => {
            html += `<li>${i.sku} - ${i.name} x ${i.quantity_returned} (${i.item_condition})</li>`;
        });
        html += '</ul><h4>Discrepanțe</h4><ul>';
        if (data.discrepancies.length === 0) {
            html += '<li>Nicio discrepanță</li>';
        } else {
            data.discrepancies.forEach(d => {
                html += `<li>${d.sku} - ${d.discrepancy_type} (${d.resolution_status})</li>`;
            });
        }
        html += '</ul>';
        document.getElementById('return-details').innerHTML = html;
        document.getElementById('return-modal').style.display = 'flex';
    } catch (err) {
        console.error('detail error', err);
    }
}

function closeReturnModal(){
    document.getElementById('return-modal').style.display = 'none';
}

async function loadChart(){
    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=stats`);
        const data = await res.json();
        const labels = data.stats.map(r => r.day);
        const values = data.stats.map(r => r.total);
        if (returnsChart) returnsChart.destroy();
        const ctx = document.getElementById('returns-chart').getContext('2d');
        returnsChart = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets: [{ label: 'Returnări', data: values, borderColor: '#1a73e8', fill: false }]},
            options: { scales: { y: { beginAtZero: true }}}
        });
    } catch (err){ console.error('chart error', err); }
}

document.addEventListener('DOMContentLoaded', () => {
    fetchSummary();
    initTable();
    loadChart();

    // filter form
    document.getElementById('filter-form').addEventListener('submit', e => {
        e.preventDefault();
        const params = new URLSearchParams(new FormData(e.target));
        const url = `${WMS_CONFIG.apiBase}/returns/admin.php?action=list&${params.toString()}`;
        returnsTable.ajax.url(url).load();
    });

    document.getElementById('export-btn').addEventListener('click', () => {
        const params = new URLSearchParams(new FormData(document.getElementById('filter-form')));
        window.open(`${WMS_CONFIG.apiBase}/returns/admin.php?action=list&export=csv&${params.toString()}`);
    });

    setInterval(() => {
        fetchSummary();
        loadChart();
        returnsTable.ajax.reload(null, false);
    }, 60000);
});
