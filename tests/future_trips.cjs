const {JSDOM}=require('jsdom');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const wait=()=>new Promise(resolve=>setTimeout(resolve,20));
function page(file){return fs.readFileSync(file,'utf8').replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'');}
(async()=>{
  const dom=new JSDOM(page('future-trips/index.php'),{runScripts:'outside-only',url:'https://test.invalid/future-trips/'});
  const w=dom.window,calls=[];
  w.confirm=()=>true;
  w.fetch=async(url,options)=>{
    if(!options?.method)return {ok:true,json:async()=>({ok:true,canEdit:true,csrf:'future-token',futureTrips:[
      {id:1,title:'Coastal Oregon',description:'Lighthouses and small towns',sortOrder:1},
      {id:2,title:'Safe text',description:'<img src=x onerror=alert(1)>',sortOrder:2}
    ]})};
    const body=JSON.parse(options.body);calls.push(body);
    if(body.action==='future_trip_add')return {ok:true,json:async()=>({ok:true,trip:{id:3,title:body.title,description:body.description,sortOrder:3}})};
    return {ok:true,json:async()=>({ok:true})};
  };
  w.eval(fs.readFileSync('assets/future-trips.js','utf8'));await wait();
  assert.equal(w.document.querySelectorAll('.future-card').length,2);
  assert.equal(w.document.querySelectorAll('.future-card img').length,0);
  w.document.querySelector('#trip-title').value='Alps by train';
  w.document.querySelector('#trip-description').value='Scenic rail and mountain villages';
  w.document.querySelector('#trip-form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await wait();
  assert.deepEqual({action:calls[0].action,title:calls[0].title,description:calls[0].description,csrf:calls[0].csrf},{action:'future_trip_add',title:'Alps by train',description:'Scenic rail and mountain villages',csrf:'future-token'});
  assert.equal(w.document.querySelectorAll('.future-card').length,3);
  w.document.querySelector('button[aria-label="Move Alps by train up"]').click();await wait();
  assert.deepEqual(calls[1].ids,[1,3,2]);
  w.document.querySelector('button[aria-label="Remove Coastal Oregon"]').click();await wait();
  assert.equal(calls[2].action,'future_trip_delete');assert.equal(calls[2].id,1);
  assert.equal(w.document.querySelectorAll('.future-card').length,2);
  dom.window.close();

  const readOnly=new JSDOM(page('future-trips/index.php'),{runScripts:'outside-only',url:'https://test.invalid/future-trips/'});
  readOnly.window.fetch=async()=>({ok:true,json:async()=>({ok:true,canEdit:false,csrf:'read-token',futureTrips:[{id:4,title:'Iceland',description:'Ring Road',sortOrder:1}]})});
  readOnly.window.eval(fs.readFileSync('assets/future-trips.js','utf8'));await wait();
  assert.equal(readOnly.window.document.querySelector('#editor').hidden,true);
  assert.equal(readOnly.window.document.querySelectorAll('.future-actions').length,0);
  readOnly.window.close();
  console.log('PASS: future trip add, safe rendering, priority reorder, removal, and read-only mode.');
})().catch(error=>{console.error(error);process.exit(1);});
