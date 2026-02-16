<?php
// Set charset
header('Content-Type: text/html; charset=utf-8');

// Четем данните от cities.json
$citiesJson = file_get_contents('cities.json');
?>
<!DOCTYPE html>
<html>
<head>
  <title>Route Progress with PHP</title>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

  <style>
    html, body { height: 100%; margin: 0; font-family: Arial; }
    body { display: flex; }
    #leftPanel { flex: 3; display: flex; flex-direction: column; height: 100vh; }
    #controls { padding: 10px; background: #f4f4f4; border-bottom: 1px solid #ccc; }
    #map { flex: 1; width: 100%; }
    #infoPanel { flex: 1; padding: 20px; background: #f9f9f9; border-left: 1px solid #ccc; min-width: 250px; }
    input { padding:5px; width:100px; }
    button { padding:6px 12px; cursor:pointer; }
  </style>
</head>
<body>

<div id="leftPanel">
  <div id="controls">
    Въведи минути:
    <input type="number" id="minutesInput" value="" min="0">
    <button onclick="updateProgress()">Покажи</button>
  </div>
  <div id="map"></div>
</div>

<div id="infoPanel">
  <h3>Информация</h3>
  <p id="formattedTime">Изминато време: -</p>
  <p id="percentInfo">Прогрес: -</p>
  <p id="expectedTime">Очаквано време: -</p>
</div>

<script>
// ===== INITIALIZATION =====
let map = L.map('map').setView([42.7, 25.0], 7);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors'
}).addTo(map);

map.createPane('routePane');
map.createPane('markerPane');
map.getPane('routePane').style.zIndex = 400;
map.getPane('markerPane').style.zIndex = 650;

// ===== CITIES from PHP =====
let cities = <?php echo $citiesJson; ?>;
let routePoints = cities.map(c => c.coords);
let greenLine, currentMarker;

// ===== Haversine =====
function getDistanceKm(lat1, lon1, lat2, lon2) {
  const R = 6371;
  const dLat = (lat2-lat1) * Math.PI/180;
  const dLon = (lon2-lon1) * Math.PI/180;
  const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return R * c;
}

// ===== Formatting =====
function formatMinutes(minutes) {
  let hours = Math.floor(minutes / 60);
  let mins = Math.floor(minutes % 60);
  return hours + " ч " + (mins<10?"0"+mins:mins) + " мин";
}

// ===== Progress point =====
function getProgressPoint(points, percent) {
  let totalSegments = points.length - 1;
  let segmentProgress = percent * totalSegments;
  let currentSegment = Math.floor(segmentProgress);
  let segmentFraction = segmentProgress - currentSegment;
  if (currentSegment >= totalSegments) return points[points.length - 1];
  let start = points[currentSegment];
  let end = points[currentSegment + 1];
  let lat = start[0] + (end[0] - start[0]) * segmentFraction;
  let lng = start[1] + (end[1] - start[1]) * segmentFraction;
  return [lat, lng];
}

// ===== Зелен маршрут =====
function getGreenRoute(points, percent) {
  let totalSegments = points.length - 1;
  let segmentProgress = percent * totalSegments;
  let currentSegment = Math.floor(segmentProgress);
  let segmentFraction = segmentProgress - currentSegment;

  let progressPoints = points.slice(0, currentSegment + 1);
  if (currentSegment < totalSegments) {
    let start = points[currentSegment];
    let end = points[currentSegment + 1];
    let lat = start[0] + (end[0] - start[0]) * segmentFraction;
    let lng = start[1] + (end[1] - start[1]) * segmentFraction;
    progressPoints.push([lat, lng]);
  }
  return progressPoints;
}

// ===== Next city =====
function getNextCity(pos) {
  for (let i = 0; i < routePoints.length - 1; i++) {
    const start = routePoints[i];
    const end = routePoints[i+1];
    const dx = end[0] - start[0];
    const dy = end[1] - start[1];
    const segmentLength = Math.sqrt(dx*dx + dy*dy);
    const dxPos = pos[0] - start[0];
    const dyPos = pos[1] - start[1];
    const posDist = Math.sqrt(dxPos*dxPos + dyPos*dyPos);
    if (posDist <= segmentLength) return cities[i+1];
  }
  return cities[cities.length-1];
}

// ===== Total time =====
function calculateTotalTime(points, avgSpeedKmh) {
  let totalKm = 0;
  for (let i=0;i<points.length-1;i++){
    totalKm += getDistanceKm(points[i][0], points[i][1], points[i+1][0], points[i+1][1]);
  }
  return Math.round((totalKm / avgSpeedKmh) * 60);
}

// ===== Initialization =====
function initMap() {
  L.polyline(routePoints, { color: 'blue', weight: 5, pane: 'routePane' }).addTo(map);

  cities.forEach((city,index)=>{
    let color = index===0?"green":index===cities.length-1?"red":"blue";
    L.circleMarker(city.coords, { radius:6, color, fillColor:color, fillOpacity:1, pane:'markerPane'})
      .addTo(map)
      .bindPopup("<b>"+city.name+"</b><br>Адрес: "+city.address);
  });

  const totalTravelTimeMinutes = calculateTotalTime(routePoints, 80);
  document.getElementById("expectedTime").innerText =
    "Очаквано време: " + formatMinutes(totalTravelTimeMinutes) + " ("+totalTravelTimeMinutes+" мин)";
}

initMap();

// ===== Update =====
function updateProgress() {
  const minutesInput = document.getElementById("minutesInput").value;
  const totalTravelTimeMinutes = calculateTotalTime(routePoints, 80);

  if (minutesInput === "" || parseFloat(minutesInput) <= 0) {
    if (greenLine) map.removeLayer(greenLine);
    if (currentMarker) map.removeLayer(currentMarker);
    L.polyline(routePoints, { color: 'blue', weight: 5, pane: 'routePane' }).addTo(map);
    document.getElementById("formattedTime").innerText = "Изминато време: -";
    document.getElementById("percentInfo").innerText = "Прогрес: -";
    document.getElementById("expectedTime").innerText =
      "Очаквано време: " + formatMinutes(totalTravelTimeMinutes) + " ("+totalTravelTimeMinutes+" мин)";
    map.fitBounds(routePoints);
    return;
  }

  const minutes = parseFloat(minutesInput);
  let percent = minutes / totalTravelTimeMinutes;
  if (percent>1) percent=1;

  const greenRoute = getGreenRoute(routePoints, percent);
  const currentPosition = getProgressPoint(routePoints, percent);

  if (greenLine) map.removeLayer(greenLine);
  if (currentMarker) map.removeLayer(currentMarker);

  greenLine = L.polyline(greenRoute, { color: 'green', weight: 6, pane: 'routePane' }).addTo(map);

  const nextCity = getNextCity(currentPosition);
  const remainingKm = Math.round(getDistanceKm(currentPosition[0], currentPosition[1], nextCity.coords[0], nextCity.coords[1]));

  currentMarker = L.circleMarker(currentPosition, { 
    radius: 6,          
    color: 'orange',    
    weight: 3,          
    fillColor: 'yellow',
    fillOpacity: 1,
    pane: 'markerPane'
  })
    .addTo(map)
    .bindPopup(
      "<b>Текуща позиция</b><br>" +
      "След " + formatMinutes(minutes) + "<br>" +
      "До " + nextCity.name + " има още " + remainingKm + " км"
    );

  document.getElementById("formattedTime").innerText = "Изминато време: " + formatMinutes(minutes);
  document.getElementById("percentInfo").innerText = "Прогрес: " + Math.round(percent*100) + "%";
  document.getElementById("expectedTime").innerText =
    "Очаквано време: " + formatMinutes(totalTravelTimeMinutes) + " ("+totalTravelTimeMinutes+" мин)";

  map.panTo(currentPosition);
}
</script>

</body>
</html>
