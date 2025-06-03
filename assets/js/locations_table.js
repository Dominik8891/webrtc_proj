$(document).ready(function () {
    // Ajax-Daten abrufen (z.B. per index.php?act=get_locations)
    $.ajax({
        url: 'index.php?act=get_locations',
        method: 'GET',
        dataType: 'json',
        success: function (data) {
            console.log(data)
            let rows = '';
            data.forEach(function (item, i) {
                let icon = item.user_status === "online" ? "🟢" : "🔴";
                rows += `<tr>
                    <td>${i + 1}</td>
                    <td>${icon}${item.username}</td>
                    <td>${item.country_name}</td>
                    <td>${item.city_name}</td>
                    <td>${item.latitude}</td>
                    <td>${item.longitude}</td>
                    <td>${item.description}</td>
                    <td>
                        <button class="btn btn-success start-call-btn" data-userid="${item.user_id}">Call</button>
                    </td>
                </tr>`;
            });
            $('#locationsTable tbody').html(rows);

            // DataTables initialisieren
            $('#locationsTable').DataTable();

            // Call-Button Handler
            $('.start-call-btn').on('click', function() {
                const userId = $(this).data('userid');
                if(typeof window.webrtcApp?.rtc?.startCall === 'function') {
                    window.webrtcApp.rtc.startCall(userId);
                } else {
                    alert("Call-Funktion nicht verfügbar.");
                }
            });
        },
        error: function () {
            $('#locationsTable tbody').html('<tr><td colspan="7">Fehler beim Laden der Daten.</td></tr>');
        }
    });
});
