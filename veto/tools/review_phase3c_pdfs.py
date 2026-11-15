"""Render every representative report page for visual QA and check text bounds."""
import json
from pathlib import Path
import pymupdf

BASE=Path(__file__).resolve().parents[1]


def main():
    output=BASE/'tmp/pdfs/phase3c-final';output.mkdir(parents=True,exist_ok=True)
    results={}
    for name in ('completed','failed','static-only','timeout'):
        doc=pymupdf.open(BASE/'output/pdf'/('veto-'+name+'.pdf'))
        folder=output/name;folder.mkdir(exist_ok=True)
        outside=[]
        for number,page in enumerate(doc):
            page.get_pixmap(matrix=pymupdf.Matrix(1,1)).save(folder/f'page-{number+1}.png')
            for block in page.get_text('dict')['blocks']:
                for line in block.get('lines',[]):
                    for span in line['spans']:
                        x0,y0,x1,y1=span['bbox']
                        if x0<45 or x1>page.rect.width-45 or y0<15 or y1>page.rect.height-15:outside.append({'page':number+1,'text':span['text'][:80],'bbox':span['bbox']})
        results[name]={'pages':len(doc),'out_of_bounds':outside,'passed':not outside}
    target=BASE/'evidence/phase3c/pdf-layout-verification.json'
    target.write_text(json.dumps({'passed':all(r['passed'] for r in results.values()),'reports':results},indent=2)+'\n',encoding='utf-8')
    print(json.dumps(results,indent=2))
    return 0 if all(r['passed'] for r in results.values()) else 1


if __name__=='__main__':raise SystemExit(main())
