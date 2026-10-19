'use strict';
let shared, trips=[], busy=false;
const list=document.querySelector('#trip-list'), statusEl=document.querySelector('#status'), form=document.querySelector('#trip-form');
async function api(body){const r=await fetch('/api/travel.php',body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf:shared.csrf})}:{cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Please sign in again.');return j;}
function element(tag,text,className){const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(className)node.className=className;return node;}
function actionButton(label,ariaLabel,onClick,className=''){const button=element('button',label,className);button.type='button';button.setAttribute('aria-label',ariaLabel);button.onclick=onClick;return button;}
function render(){
  list.replaceChildren();
  document.querySelector('#trip-count').textContent=`${trips.length} ${trips.length===1?'idea':'ideas'}`;
  document.querySelector('#empty').hidden=trips.length>0;
  trips.forEach((trip,index)=>{
    const item=element('li',undefined,'future-card panel');
    item.dataset.tripId=trip.id;
    const rank=element('div',String(index+1),'priority-number');
    rank.setAttribute('aria-label',`Priority ${index+1}`);
    const copy=element('div',undefined,'future-copy');
    copy.append(element('h3',trip.title),element('p',trip.description));
    item.append(rank,copy);
    if(shared.canEdit){
      const actions=element('div',undefined,'future-actions');
      const up=actionButton('↑',`Move ${trip.title} up`,()=>move(trip.id,-1));
      const down=actionButton('↓',`Move ${trip.title} down`,()=>move(trip.id,1));
      up.disabled=busy||index===0;
      down.disabled=busy||index===trips.length-1;
      const remove=actionButton('Remove',`Remove ${trip.title}`,()=>removeTrip(trip),'danger');
      remove.disabled=busy;
      actions.append(up,down,remove);
      item.append(actions);
    }
    list.append(item);
  });
}
async function move(id,offset){
  if(busy)return;
  const index=trips.findIndex(trip=>trip.id===id), target=index+offset;
  if(index<0||target<0||target>=trips.length)return;
  const previous=[...trips];
  [trips[index],trips[target]]=[trips[target],trips[index]];
  busy=true;render();statusEl.textContent='Saving priority…';
  try{await api({action:'future_trip_reorder',ids:trips.map(trip=>trip.id)});statusEl.textContent='Priority saved across your devices.';}
  catch(error){trips=previous;statusEl.textContent=error.message;}
  finally{busy=false;render();}
}
async function removeTrip(trip){
  if(busy||!confirm(`Remove “${trip.title}” from future trips?`))return;
  busy=true;render();statusEl.textContent='Removing…';
  try{await api({action:'future_trip_delete',id:trip.id});trips=trips.filter(item=>item.id!==trip.id);statusEl.textContent=`Removed ${trip.title}.`;}
  catch(error){statusEl.textContent=error.message;}
  finally{busy=false;render();}
}
form?.addEventListener('submit',async event=>{
  event.preventDefault();
  if(busy)return;
  const title=document.querySelector('#trip-title').value.trim(),description=document.querySelector('#trip-description').value.trim();
  if(!title||!description){statusEl.textContent='Enter both a title and description.';return;}
  busy=true;render();statusEl.textContent='Adding trip…';
  try{const result=await api({action:'future_trip_add',title,description});trips.push(result.trip);form.reset();statusEl.textContent=`Added ${result.trip.title}.`;}
  catch(error){statusEl.textContent=error.message;}
  finally{busy=false;render();}
});
api().then(data=>{shared=data;trips=Array.isArray(data.futureTrips)?data.futureTrips:[];const editor=document.querySelector('#editor');if(editor)editor.hidden=!data.canEdit;render();statusEl.textContent=data.canEdit?'Add ideas, remove them, or adjust their priority.':'Future trip ideas are read-only.';}).catch(error=>{const editor=document.querySelector('#editor');if(editor)editor.hidden=true;statusEl.textContent=error.message;});
