<x-filament::section>
    <x-slot name="heading">节点分布</x-slot>
    <x-slot name="description">按区域聚合的节点，圆点大小代表在线用户数（10 秒自动刷新）</x-slot>

    <div wire:poll.10s>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
        <div id="cp-node-map-{{ $this->getId() }}" style="height: 340px; border-radius: 0.75rem; overflow: hidden;"></div>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
        <script>
            (function () {
                const id = 'cp-node-map-{{ $this->getId() }}';
                const markers = @json($this->getMarkers());

                function boot() {
                    const el = document.getElementById(id);
                    if (! el || typeof L === 'undefined') { return; }
                    if (window.cpNodeMap) { window.cpNodeMap.remove(); }
                    const map = L.map(el, { zoomControl: true, attributionControl: false }).setView([22, 40], 2);
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 8 }).addTo(map);
                    markers.forEach(function (m) {
                        const color = m.status === 'online' ? '#10b981' : '#ef4444';
                        L.circleMarker([m.lat, m.lon], {
                            radius: 6 + Math.min(14, m.users / 4),
                            color: color, fillColor: color, fillOpacity: 0.55, weight: 2,
                        })
                            .bindPopup('<b>' + m.name + '</b><br>' + (m.region || '-') + ' · ' + m.status
                                + '<br>用户 ' + m.users + ' · CPU ' + m.cpu + '%')
                            .addTo(map);
                    });
                    window.cpNodeMap = map;
                }

                if (document.readyState !== 'loading') { boot(); }
                else { document.addEventListener('DOMContentLoaded', boot); }
            })();
        </script>
    </div>
</x-filament::section>
