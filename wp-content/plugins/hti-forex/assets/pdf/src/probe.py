"""Instrument the composed sheet so a browser can report the gap to the footer.

The footer is position:absolute, so overflowing content silently prints on top
of it. Measuring the distance from the last flowed block to the footer is the
only way to see that before it reaches a reader.
"""
import io
import sys

PROBE = """<script>window.addEventListener('load',function(){
var out=[];document.querySelectorAll('.page').forEach(function(p,i){
  var f=p.querySelector('footer'), last=null;
  Array.prototype.forEach.call(p.children,function(c){if(c.tagName!=='FOOTER'){last=c;}});
  out.push('P'+(i+1)+'='+Math.round(f.getBoundingClientRect().top-last.getBoundingClientRect().bottom));
});document.title=out.join(' ');});</script>"""

html = io.open(sys.argv[1], encoding='utf-8').read().replace('</body>', PROBE + '</body>', 1)
io.open(sys.argv[2], 'w', encoding='utf-8').write(html)
