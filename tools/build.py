# Builds index.html, manifest.webmanifest and icons from src.html. Run: python3 tools/build.py  (needs Pillow)
import json, os
from PIL import Image, ImageDraw
os.chdir(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..'))
def icon(size, maskable=False, path=None):
    im=Image.new('RGBA',(size,size),(0,0,0,0)); d=ImageDraw.Draw(im)
    s=size
    if maskable: d.rectangle([0,0,s,s],fill='#0F6B57'); k=0.62
    else: d.rounded_rectangle([0,0,s-1,s-1],radius=int(s*.22),fill='#0F6B57'); k=0.78
    c=s/2; w=s*k/2
    # Tugrik sign: T with two slanted bars
    th=s*k*0.11
    top=c-w*0.78; bot=c+w*0.82
    d.rectangle([c-w*0.62,top,c+w*0.62,top+th],fill='white')
    d.rectangle([c-th/2,top,c+th/2,bot],fill='white')
    for yy in (c-w*0.05,c+w*0.32):
        x1,x2=c-w*0.42,c+w*0.42; dy=w*0.14
        d.polygon([(x1,yy+dy),(x2,yy-dy),(x2,yy-dy+th*0.9),(x1,yy+dy+th*0.9)],fill='white')
    im.save(path)
icon(192,path='icon-192.png'); icon(512,path='icon-512.png'); icon(512,True,'icon-maskable.png'); icon(180,True,'apple-touch-icon.png')
json.dump({"name":"Төгрөг — хувийн санхүү","short_name":"Төгрөг","description":"Орлого, зарлага, төсвөө хөтлөх офлайн апп","lang":"mn","start_url":"./#home","scope":"./","display":"standalone","orientation":"portrait","background_color":"#EDF1EE","theme_color":"#0F6B57",
 "icons":[{"src":"icon-192.png","sizes":"192x192","type":"image/png"},{"src":"icon-512.png","sizes":"512x512","type":"image/png"},{"src":"icon-maskable.png","sizes":"512x512","type":"image/png","purpose":"maskable"}],
 "share_target":{"action":"./","method":"GET","params":{"title":"title","text":"text"}},
 "shortcuts":[{"name":"Гүйлгээ","url":"./#txs"},{"name":"Тайлан","url":"./#report"}]},open('manifest.webmanifest','w',encoding='utf-8'),ensure_ascii=False,indent=1)
src=open('src.html',encoding='utf-8').read()
head='''<!doctype html>
<html lang="mn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0F6B57">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Төгрөг">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon-192.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<style>:root{padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}body{margin:0;font-size:14px}[hidden]{display:none!important}img{max-width:100%}</style>
'''
title_end=src.index('<style>')
open('index.html','w',encoding='utf-8').write(head+src[:title_end]+'</head>\n<body>\n'+src[title_end:]+'\n</body>\n</html>\n')
