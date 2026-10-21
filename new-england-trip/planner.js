'use strict';

let shared, stops=[], map, routeLayer, activeBaseLayer;
const markers=new Map(), routeLayers=[];
const statusEl=document.querySelector('#status');
const routeColors=['#0f6c81','#d46b38','#2f7d55','#7157a8','#bb4d68','#5f7f13'];
const mapboxToken='pk.'+'eyJ1Ijoia2lscm95NjgiLCJhIjoiY21xanJiZmp5MGJvbzJ5b244cGZ5MzlhMCJ9'+'.JgIP1KGPEgunpnZzEVmfHg';

function el(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;}
async function request(body){const r=await fetch('/api/travel.php',body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf:shared.csrf})}:{cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Please sign in again.');return j;}
function mapboxLayer(styleId){return L.tileLayer(`https://api.mapbox.com/styles/v1/mapbox/${styleId}/tiles/512/{z}/{x}/{y}@2x?access_token=${mapboxToken}`,{tileSize:512,zoomOffset:-1,maxZoom:20,attribution:'© Mapbox © OpenStreetMap'});}
function setMapStyle(styleId){if(!map)return;if(activeBaseLayer)map.removeLayer(activeBaseLayer);activeBaseLayer=mapboxLayer(styleId).addTo(map);}
function mapsUrl(s){return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${s.lat},${s.lng}`)}`;}
function appleMapsUrl(s){return `https://maps.apple.com/?ll=${encodeURIComponent(`${s.lat},${s.lng}`)}&q=${encodeURIComponent(s.name)}`;}
function weatherUrl(s){return `https://forecast.weather.gov/MapClick.php?lat=${encodeURIComponent(s.lat)}&lon=${encodeURIComponent(s.lng)}`;}
function appendLink(parent,label,href){const a=el('a',label);a.href=href;a.target='_blank';a.rel='noopener';parent.append(a);}
function popupForStop(s){const box=el('div');box.append(el('h3',s.name),el('div',`${s.dates} · ${s.nights?s.nights+' night'+(s.nights>1?'s':''):'Departure day'}`,'muted'));const ul=el('ul');s.activities.forEach(a=>ul.append(el('li',a)));box.append(ul);const links=el('p');appendLink(links,'Google Maps',mapsUrl(s));links.append(' · ');appendLink(links,'Apple Maps',appleMapsUrl(s));links.append(' · ');appendLink(links,'Weather',weatherUrl(s));box.append(links);return box;}
function popupPadding(){return window.innerWidth<850?L.point(24,96):L.point(28,72);}
function panPopupFullyIntoView(){
  const popup=document.querySelector('.leaflet-popup');if(!popup)return;
  const close=popup.querySelector('.leaflet-popup-close-button'),popupRect=popup.getBoundingClientRect(),closeRect=close?close.getBoundingClientRect():popupRect;
  const rect={left:Math.min(popupRect.left,closeRect.left),right:Math.max(popupRect.right,closeRect.right),top:Math.min(popupRect.top,closeRect.top),bottom:Math.max(popupRect.bottom,closeRect.bottom)};
  const pad=window.innerWidth<850?28:14,topPad=window.innerWidth<850?82:54;let dx=0,dy=0;
  if(rect.left<pad)dx=rect.left-pad;else if(rect.right>window.innerWidth-pad)dx=rect.right-(window.innerWidth-pad);
  if(rect.top<topPad)dy=rect.top-topPad;else if(rect.bottom>window.innerHeight-pad)dy=rect.bottom-(window.innerHeight-pad);
  if(dx||dy)map.panBy([dx,dy],{animate:true,duration:.2});
}
function focusStop(s){document.querySelectorAll('.stop').forEach(e=>e.classList.toggle('active',e.id===s.id));if(map){map.flyTo([s.lat,s.lng],10,{duration:.75});markers.get(s.id)?.openPopup();setTimeout(panPopupFullyIntoView,850);if(window.innerWidth<850)document.querySelector('#route-map')?.scrollIntoView({behavior:'smooth',block:'start'});}}

function renderStops(){
  const parent=document.querySelector('#stops');parent.replaceChildren();
  for(const s of stops){
    const card=el('article',undefined,'stop');card.id=s.id;
    card.append(el('h2',s.name),el('div',`${s.dates} · ${s.nights?s.nights+' night'+(s.nights>1?'s':''):'Departure day'}`,'muted'));
    const ul=el('ul');s.activities.forEach(a=>ul.append(el('li',a)));card.append(ul);
    const links=el('div',undefined,'links'),focus=el('button','Show on map');focus.type='button';focus.onclick=()=>focusStop(s);links.append(focus);
    const i=stops.indexOf(s),prev=stops[Math.max(0,i-1)];
    const directions=el('a',i?'Driving directions ↗':'Open in Maps ↗');directions.href=i?`https://www.google.com/maps/dir/?api=1&origin=${prev.lat},${prev.lng}&destination=${s.lat},${s.lng}&travelmode=driving`:mapsUrl(s);directions.target='_blank';directions.rel='noopener';links.append(directions);card.append(links);
    if(s.id==='north-conway'){const scenic=el('a','Scenic route via Franconia Notch + Kancamagus Highway ↗');scenic.href='https://www.google.com/maps/dir/?api=1&origin=Stowe+VT&destination=North+Conway+NH&waypoints=Franconia+Notch+NH%7CLincoln+NH%7CKancamagus+Highway+NH&travelmode=driving';scenic.target='_blank';scenic.rel='noopener';card.append(scenic);}
    const details=el('details'),summary=el('summary','Lodging, reservations & notes');details.append(summary);const form=el('form'),saved=shared?.plans?.[s.id]||{};
    for(const [key,title,type,max] of [['date','Arrival / activity date','date',10],['lodging','Hotel or reservation','text',200],['confirmation','Confirmation reference','text',200],['cost','Estimated cost','text',30],['notes','Shared notes','textarea',5000]]){const label=el('label',title),input=el(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;input.name=key;input.maxLength=max;input.value=saved[key]??(key==='date'?s.date:'');if(type==='date'){input.min='2027-01-01';input.max='2027-12-31';}input.disabled=!shared?.canEdit;label.append(input);form.append(label);}
    const msg=el('div','', 'save-status');msg.setAttribute('role','status');
    if(shared?.canEdit){const save=el('button','Save this stop','primary');save.type='submit';form.append(save);form.onsubmit=async e=>{e.preventDefault();save.disabled=true;msg.textContent='Saving…';const values=Object.fromEntries(new FormData(form));try{await request({action:'plan',stop_id:s.id,...values});shared.plans[s.id]=values;msg.textContent='Saved across your devices.';}catch(err){msg.textContent=err.message;}finally{save.disabled=false;}};}else form.append(el('p','Shared planning details are read-only.','muted'));
    form.append(msg);details.append(form);card.append(details);parent.append(card);
  }
}

function nearestRouteIndex(coords,stop,start){let best=start,bestDistance=Infinity;for(let i=start;i<coords.length;i++){const dx=coords[i][0]-stop.lng,dy=coords[i][1]-stop.lat,d=dx*dx+dy*dy;if(d<bestDistance){best=i;bestDistance=d;}}return best;}
function splitRoute(coords){const legs=[];let start=0;for(let i=1;i<stops.length;i++){let end=nearestRouteIndex(coords,stops[i],start);if(end<=start)end=Math.min(coords.length-1,start+1);legs.push(coords.slice(start,end+1));start=end;}return legs;}
function routeMiles(coords){let total=0;for(let i=1;i<coords.length;i++){const [lng1,lat1]=coords[i-1],[lng2,lat2]=coords[i];const p1=lat1*Math.PI/180,p2=lat2*Math.PI/180,dp=(lat2-lat1)*Math.PI/180,dl=(lng2-lng1)*Math.PI/180;const a=Math.sin(dp/2)**2+Math.cos(p1)*Math.cos(p2)*Math.sin(dl/2)**2;total+=3958.8*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));}return total;}
function fit(){if(map&&routeLayer)map.fitBounds(routeLayer.getBounds(),{padding:[34,34]});}

async function initMap(){
  try{
    if(typeof L==='undefined')throw Error('Map library unavailable');
    map=L.map('route-map',{scrollWheelZoom:true,preferCanvas:true,zoomControl:false}).setView([43.5,-70.9],6);
    L.control.zoom({position:'bottomleft'}).addTo(map);setMapStyle(document.querySelector('#style-select').value);
    const response=await fetch('route.json');if(!response.ok)throw Error('Route unavailable');const geo=await response.json();const coords=geo.geometry?.coordinates;
    if(!Array.isArray(coords)||coords.length<2)throw Error('Route unavailable');
    splitRoute(coords).forEach((leg,i)=>{const latlngs=leg.map(([lng,lat])=>[lat,lng]);const name=`${stops[i].name} → ${stops[i+1].name}`;const layer=L.polyline(latlngs,{color:routeColors[i%routeColors.length],weight:5,opacity:.9}).addTo(map).bindTooltip(`${name}<br>${routeMiles(leg).toFixed(0)} miles`);routeLayers.push(layer);});
    routeLayer=L.featureGroup(routeLayers);
    stops.forEach(s=>{const marker=L.marker([s.lat,s.lng],{title:s.name}).addTo(map).bindPopup(popupForStop(s),{maxWidth:390,autoPan:true,keepInView:true,autoPanPaddingTopLeft:popupPadding(),autoPanPaddingBottomRight:popupPadding()});marker.on('click',()=>{document.getElementById(s.id)?.scrollIntoView({behavior:'smooth',block:'nearest'});document.querySelectorAll('.stop').forEach(e=>e.classList.toggle('active',e.id===s.id));});markers.set(s.id,marker);});
    map.on('popupopen',()=>setTimeout(panPopupFullyIntoView,80));
    fit();document.querySelector('#map-message').textContent=`Mapbox driving map · ${routeLayers.length} colored travel legs · click any stop for details.`;
  }catch(e){document.querySelector('#map-message').textContent='Map unavailable. Use the driving-direction links beside each stop.';}
}

(async()=>{try{const r=await fetch('itinerary.json');if(!r.ok)throw Error('Itinerary could not be loaded.');stops=await r.json();try{shared=await request();if(Array.isArray(shared.plans))shared.plans={};statusEl.textContent=shared.canEdit?'Planning details save securely across devices.':'Viewing shared itinerary.';}catch(e){statusEl.textContent=e.message+' Itinerary remains available; reload to edit.';}renderStops();await initMap();}catch(e){statusEl.textContent=e.message;}})();
document.querySelector('#search').oninput=e=>{let visible=0;for(const s of stops){const card=document.getElementById(s.id);card.hidden=!card.textContent.toLowerCase().includes(e.target.value.toLowerCase());if(!card.hidden)visible++;}document.querySelector('#empty').hidden=visible>0;};
document.querySelector('#fit-route').onclick=fit;
document.querySelector('#print').onclick=()=>window.print();
document.querySelector('#style-select').onchange=e=>setMapStyle(e.target.value);
const plannerShell=document.querySelector('#planner-shell'),sidebarToggle=document.querySelector('#sidebar-toggle');
function updateSidebarToggle(){const collapsed=plannerShell.classList.contains('sidebar-collapsed');sidebarToggle.textContent=collapsed?'Show planner':'Hide planner';sidebarToggle.setAttribute('aria-expanded',collapsed?'false':'true');setTimeout(()=>map?.invalidateSize(),260);}
sidebarToggle.onclick=()=>{plannerShell.classList.toggle('sidebar-collapsed');updateSidebarToggle();};
updateSidebarToggle();
document.addEventListener('keydown',e=>{if(e.key==='Escape')map?.closePopup();});
