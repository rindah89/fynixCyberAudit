#!/usr/bin/env python3
import argparse,json,os,re,stat
from pathlib import Path
class Error(RuntimeError): pass
def canonical(v): return json.dumps(v,sort_keys=True,separators=(",",":"))
def read_json(path):
 p=Path(path); s=p.lstat()
 if not stat.S_ISREG(s.st_mode) or stat.S_IMODE(s.st_mode)&0o077 or s.st_size>65536: raise Error("unsafe receipt input")
 v=json.loads(p.read_text())
 if not isinstance(v,dict): raise Error("receipt must be an object")
 return v
def secret_free(v):
 forbidden=re.compile(r"(^|_)(claim_token|api_key|secret|private_key|bearer|password)($|_)")
 if isinstance(v,dict):
  for k,x in v.items():
   if forbidden.search(str(k).lower()): raise Error("secret/token field rejected")
   secret_free(x)
 elif isinstance(v,list):
  for x in v: secret_free(x)
def atomic(path,value):
 raw=(canonical(value)+"\n").encode(); tmp=path.with_name(path.name+".new")
 if path.exists():
  if path.read_bytes()!=raw: raise Error("receipt replay conflicts with retained evidence")
  return
 fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
 try: os.write(fd,raw); os.fsync(fd)
 finally: os.close(fd)
 os.replace(tmp,path)
def persist(directory,operation,itsm,cyber,binding):
 if not re.fullmatch(r"[0-9a-f-]{36}",operation): raise Error("operation id invalid")
 root=Path(directory); s=root.lstat()
 if not stat.S_ISDIR(s.st_mode) or stat.S_IMODE(s.st_mode)&0o077: raise Error("receipt directory must be owner-only")
 for v in (itsm,cyber,binding): secret_free(v)
 if itsm.get("operation_id")!=operation or cyber.get("operation_id")!=operation: raise Error("receipt operation mismatch")
 index={"version":"fynix-cyberaudit-deploy-evidence/v1","operation_id":operation,"binding_digest":binding.get("binding_digest"),"itsm_receipt_digest":itsm.get("receipt_digest"),"cyber_receipt_digest":cyber.get("receipt_digest")}
 for name,value in (("itsm-receipt.json",itsm),("cyber-receipt.json",cyber),("binding.json",binding),("index.json",index)): atomic(root/f"{operation}-{name}",value)
 fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
 try: os.fsync(fd)
 finally: os.close(fd)
 return index
def main():
 p=argparse.ArgumentParser();p.add_argument("--directory",required=True);p.add_argument("--operation-id",required=True);p.add_argument("--itsm-receipt",required=True);p.add_argument("--binding",required=True);a=p.parse_args()
 try:
  combined=read_json(a.itsm_receipt)
  if set(combined)!={"itsm","cyberaudit"}: raise Error("combined receipt schema invalid")
  print(canonical(persist(a.directory,a.operation_id,combined["itsm"],combined["cyberaudit"],read_json(a.binding))));return 0
 except (Error,OSError,ValueError) as e: print("receipt-persistence: "+str(e),file=os.sys.stderr);return 2
if __name__=="__main__": raise SystemExit(main())
