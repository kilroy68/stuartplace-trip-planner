const {JSDOM}=require('jsdom');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const wait=()=>new Promise(r=>setTimeout(r,20));
function page(file){return fs.readFileSync(file,'utf8').replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'');}
(async()=>{
 const home=new JSDOM(page('index.php').replace(/<div class="us-map">[\s\S]*?<\/div>/,`<div class="us-map">${fs.readFileSync('assets/us-map.svg','utf8')}</div>`),{runScripts:'outside-only',url:'https://test.invalid/'});
 const w=home.window,calls=[];
 w.fetch=async(url,opts)=>{if(opts?.method==='POST'){calls.push(JSON.parse(opts.body));return {ok:true,json:async()=>({ok:true})};}return {ok:true,json:async()=>({ok:true,visits:{David:[],Angela:[]},plans:{},canEdit:true,csrf:'test-token'})};};
 w.eval(fs.readFileSync('assets/home.js','utf8'));await wait();
 assert.equal(w.document.querySelectorAll('#state-list button').length,50);
 const person=w.document.querySelector('#person');person.value='David';person.dispatchEvent(new w.Event('change'));
 w.document.querySelector('path[data-state="Mississippi"]').dispatchEvent(new w.Event('click'));await wait();
 assert.equal(calls[0].person,'David');assert.equal(calls[0].csrf,'test-token');assert.equal(calls[0].visited,true);assert.equal(w.document.querySelector('#count').textContent,'1 / 50 states');
 assert.equal(w.document.querySelector('path[data-state="Mississippi"]').dataset.visit,'david');
 person.value='Angela';person.dispatchEvent(new w.Event('change'));assert.equal(w.document.querySelector('#count').textContent,'0 / 50 states');
 w.document.querySelector('path[data-state="Mississippi"]').dispatchEvent(new w.Event('click'));await wait();assert.equal(w.document.querySelector('path[data-state="Mississippi"]').dataset.visit,'both');
 w.fetch=async()=>({ok:false,json:async()=>({ok:false,error:'Save failed'})});
 w.document.querySelector('path[data-state="Alabama"]').dispatchEvent(new w.Event('click'));await wait();assert.equal(w.document.querySelector('path[data-state="Alabama"]').dataset.visit,'');assert.equal(w.document.querySelector('#status').textContent,'Save failed');
 home.window.close();
 const planner=new JSDOM(page('new-england-trip/index.php'),{runScripts:'outside-only',url:'https://test.invalid/new-england-trip/'}),pw=planner.window;
 pw.fetch=async(url)=>({ok:true,json:async()=>url==='itinerary.json'?JSON.parse(fs.readFileSync('new-england-trip/itinerary.json')):{ok:true,canEdit:false,plans:{boston:{notes:'<img src=x onerror=alert(1)>'}}}});
 pw.eval(fs.readFileSync('new-england-trip/planner.js','utf8'));await wait();
 assert.equal(pw.document.querySelectorAll('.stop').length,7);assert.equal(pw.document.querySelectorAll('button[type=submit]').length,0);assert.equal(pw.document.querySelector('#boston textarea').value,'<img src=x onerror=alert(1)>');assert.equal(pw.document.querySelectorAll('.stop img').length,0);
 const search=pw.document.querySelector('#search');search.value='Stowe';search.dispatchEvent(new pw.Event('input'));assert.equal([...pw.document.querySelectorAll('.stop')].filter(x=>!x.hidden).length,1);
 assert.match(pw.document.querySelector('#map-message').textContent,/Map unavailable/);planner.window.close();
 console.log('PASS: separate travelers, shared-state colors, save rollback, read-only planner, safe note rendering, search and map fallback.');
})().catch(e=>{console.error(e);process.exit(1);});
