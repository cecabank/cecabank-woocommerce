(()=>{"use strict";const e=window.React,t=window.wp.htmlEntities,{registerPaymentMethod:n}=window.wc.wcBlocksRegistry,{getSetting:i}=window.wc.wcSettings,a=i("cecabank_gateway_data",{}),l=(0,t.decodeEntities)(a.title),c=()=>(0,t.decodeEntities)(a.description||""),r=()=>a.icon?(0,e.createElement)("img",{src:a.icon,style:{float:"right",marginRight:"20px"}}):"";n({name:"cecabank_gateway",label:(0,e.createElement)((()=>(0,e.createElement)("span",{style:{width:"100%"}},l,(0,e.createElement)(r,null))),null),content:(0,e.createElement)(c,null),edit:(0,e.createElement)(c,null),canMakePayment:()=>!0,ariaLabel:l,supports:{features:a.supports}})})();