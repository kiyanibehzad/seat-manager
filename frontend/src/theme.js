import {mountPalette} from './palette.js';
export function mountTheme(root=document.documentElement){
  let choice='system';
  try{const saved=localStorage.getItem('acc-appearance');if(['system','light','dark'].includes(saved))choice=saved;}catch{}
  const palette=mountPalette(root);
  function apply(){root.dataset.theme=choice;for(const radio of document.querySelectorAll('[name="appearance"]'))radio.checked=radio.value===choice;}
  function change(event){if(!event.target.matches('[name="appearance"]')||!event.target.checked||!['system','light','dark'].includes(event.target.value))return;choice=event.target.value;apply();try{localStorage.setItem('acc-appearance',choice);}catch{}}
  document.addEventListener('change',change);apply();
  return {sync(){apply();palette.sync();},setUser:palette.setUser,destroy(){document.removeEventListener('change',change);palette.destroy();}};
}
