#!/usr/bin/env python3
"""Closed ITSM/CyberAudit authorization adapter for CyberAudit releases."""
import argparse,base64,hashlib,hmac,json,os,re,stat,subprocess,tempfile,urllib.error,urllib.request,uuid
from datetime import datetime,timezone,timedelta
from pathlib import Path

BASE="https://itsm.fynixhq.com/api/v1"; CYBER_BASE="https://cyberaudit.fynixhq.com/api"; PROFILE="fynix-cyberaudit/deploy-release"
class Denied(RuntimeError): pass
def canonical(v): return json.dumps(v,sort_keys=True,separators=(",",":"))
def locked(path,limit=65536):
 p=Path(path); s=p.lstat()
 if not stat.S_ISREG(s.st_mode) or stat.S_IMODE(s.st_mode)&0o077 or s.st_size>limit: raise Denied("credential/evidence file must be owner-only, regular, and bounded")
 return p.read_text()
def load(path):
 try: return json.loads(locked(path))
 except (ValueError,OSError) as e: raise Denied("JSON file invalid") from e
def digest(v): return hashlib.sha256(canonical(v).encode()).hexdigest()
def claim_token(secret,origin,authorization_id,nonce,subject_digest):
 return hmac.new(secret.encode(),f"{origin}:{authorization_id}:{nonce}:{subject_digest}".encode(),hashlib.sha256).hexdigest()
def request(binding):
 fixed={"contract_version":"fynix-change-authorization/v2","profile":PROFILE,"producer":"fynix-cyberaudit-release","target":"fynix-cyberaudit","environment":"production","operation":"deploy-release","rollback_compatible":True}
 body={**fixed,**binding}; body["binding_digest"]=digest(body); validate_request(body); return body
def validate_request(b):
 fields={"contract_version","profile","company_id","suite_tenant_id","customer_id","producer","request_id","target","environment","operation","release_sha","image_digest","artifact_sha256","manifest_sha256","previous_release_sha","previous_image_digest","previous_artifact_sha256","rollback_ref","soak_receipt_sha256","soak_evidence_sha256","readiness_evidence_sha256","rollback_compatible","binding_digest"}
 if set(b)!=fields or b["profile"]!=PROFILE or b["contract_version"]!="fynix-change-authorization/v2" or b["rollback_compatible"] is not True: raise Denied("closed release request invalid")
 for k in ("release_sha","previous_release_sha"):
  if not re.fullmatch(r"[a-f0-9]{40}",str(b[k])): raise Denied(k+" invalid")
 for k in ("artifact_sha256","manifest_sha256","previous_artifact_sha256","soak_receipt_sha256","soak_evidence_sha256","readiness_evidence_sha256","binding_digest"):
  if not re.fullmatch(r"[a-f0-9]{64}",str(b[k])): raise Denied(k+" invalid")
 for k in ("image_digest","previous_image_digest"):
  if not re.fullmatch(r"sha256:[a-f0-9]{64}",str(b[k])): raise Denied(k+" invalid")
 expected=f"fynix-release:{b['previous_release_sha']}@{b['previous_image_digest']}#sha256:{b['previous_artifact_sha256']}"
 if b["rollback_ref"]!=expected or b["binding_digest"]!=digest({k:v for k,v in b.items() if k!="binding_digest"}): raise Denied("release binding invalid")
 uuid.UUID(b["suite_tenant_id"]); uuid.UUID(b["customer_id"]); uuid.UUID(b["request_id"])
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*_): return None
def client(token):
 if not token or len(token)>4096: raise Denied("ITSM credential invalid")
 opener=urllib.request.build_opener(NoRedirect)
 def call(method,path,body=None):
  if not path.startswith("/change-authorizations"): raise Denied("ITSM path invalid")
  url=BASE+path; raw=None if body is None else canonical(body).encode(); req=urllib.request.Request(url,data=raw,method=method,headers={"Authorization":"Bearer "+token,"Content-Type":"application/json","Accept":"application/json"})
  try:
   with opener.open(req,timeout=15) as r: data=r.read(65537); final=r.geturl()
  except urllib.error.HTTPError as e: raise Denied(f"ITSM HTTP {e.code}") from e
  except urllib.error.URLError as e: raise Denied("ITSM unavailable") from e
  if final!=url or len(data)>65536: raise Denied("ITSM response rejected")
  try: envelope=json.loads(data)
  except ValueError as e: raise Denied("ITSM response malformed") from e
  if set(envelope)!={"data"} or not isinstance(envelope["data"],dict): raise Denied("ITSM response schema invalid")
  return envelope["data"]
 return call
def cyber_client(token):
 if not token or len(token)>4096: raise Denied("CyberAudit credential invalid")
 opener=urllib.request.build_opener(NoRedirect)
 def call(method,path,body=None):
  if not path.startswith("/evidence-authorizations"): raise Denied("CyberAudit path invalid")
  url=CYBER_BASE+path; raw=None if body is None else canonical(body).encode(); req=urllib.request.Request(url,data=raw,method=method,headers={"Authorization":"Bearer "+token,"Content-Type":"application/json","Accept":"application/json"})
  try:
   with opener.open(req,timeout=15) as response: data=response.read(65537); final=response.geturl()
  except urllib.error.HTTPError as e: raise Denied(f"CyberAudit HTTP {e.code}") from e
  except urllib.error.URLError as e: raise Denied("CyberAudit unavailable") from e
  if final!=url or len(data)>65536: raise Denied("CyberAudit response rejected")
  try: value=json.loads(data)
  except ValueError as e: raise Denied("CyberAudit response malformed") from e
  if not isinstance(value,dict): raise Denied("CyberAudit response schema invalid")
  return value
 return call
def public(row,body):
 expected=set(body)|{"id","change_id","policy_version","approval_revision","revoked","created_at","expires_at"}
 if set(row)!=expected: raise Denied("ITSM public schema invalid")
 for k,v in body.items():
  if row.get(k)!=v: raise Denied("ITSM public binding mismatch")
 if row.get("revoked") is not False or row.get("policy_version")!="fynix-production-deploy/v2" or not isinstance(row.get("id"),int) or not isinstance(row.get("change_id"),int): raise Denied("ITSM authorization is not current")
 created=when(row["created_at"],"authorization created"); expires=when(row["expires_at"],"authorization expiry"); now=datetime.now(timezone.utc)
 if created>now+timedelta(minutes=5) or not now<expires<=created+timedelta(hours=48,minutes=5): raise Denied("ITSM authorization chronology invalid")
 return row
def keys(path):
 value=load(path)
 if not isinstance(value,dict) or not 1<=len(value)<=2 or any(not re.fullmatch(r"[A-Za-z0-9._-]{1,64}",str(k)) or not isinstance(v,str) or len(v)<32 for k,v in value.items()): raise Denied("key set invalid")
 return value
def when(v,label):
 try:
  t=datetime.fromisoformat(v.replace("Z","+00:00"))
  if t.tzinfo is None: raise ValueError
  return t.astimezone(timezone.utc)
 except (AttributeError,TypeError,ValueError) as e: raise Denied(label+" timestamp invalid") from e
CYBER_REQUEST_FIELDS={"contract_version","profile","company_id","suite_tenant_id","customer_id","producer","request_id","target","environment","operation","purpose","operation_id","policy_version","release_sha","image_digest","artifact_sha256","manifest_sha256","previous_release_sha","previous_image_digest","previous_artifact_sha256","rollback_ref","rollback_compatible","itsm_change_id","itsm_authorization_id","itsm_approval_revision","itsm_binding_digest","soak_receipt_sha256","soak_evidence_sha256","readiness_evidence_sha256","request_digest"}
def verify_cyber_request(v,body,public_row,authorization_id,operation_id):
 if set(v)!=CYBER_REQUEST_FIELDS or v.get("contract_version")!="fynix-cyberaudit-evidence-authorization-request/v3" or v.get("profile")!=PROFILE or v.get("producer")!="fynix-cyberaudit-release" or v.get("target")!="fynix-cyberaudit" or v.get("environment")!="production" or v.get("operation")!="deploy-release" or v.get("purpose")!="deploy" or v.get("rollback_compatible") is not True or v.get("request_digest")!=digest({k:x for k,x in v.items() if k!="request_digest"}): raise Denied("CyberAudit v3 request invalid")
 mapped={k:x for k,x in body.items() if k not in {"contract_version","binding_digest"}}; mapped.update({"company_id":body["company_id"],"policy_version":"fynix-production-deploy/v2","itsm_change_id":public_row["change_id"],"itsm_authorization_id":authorization_id,"itsm_approval_revision":public_row["approval_revision"],"itsm_binding_digest":body["binding_digest"]})
 if any(v.get(k)!=x for k,x in mapped.items()) or v.get("operation_id")!=operation_id: raise Denied("CyberAudit request differs from ITSM/release binding")
 if v.get("itsm_binding_digest")!=body["binding_digest"]: raise Denied("CyberAudit ITSM binding invalid")
 return v
def verify_cyber(r,keyset,expected):
 d=digest({k:v for k,v in r.items() if k not in ("receipt_digest","signature")}); fields=CYBER_REQUEST_FIELDS|{"version","origin","accepted","requested_at","reviewed_at","observed_at","issued_at","expires_at","consumed_at","reviewer_id","authority","authority_binding_version","authority_binding_verified_at","authority_binding_digest","claim_nonce","key_id","receipt_digest","signature"}
 if set(r)!=fields or any(r.get(k)!=v for k,v in expected.items()) or r.get("version")!="fynix-cyberaudit-evidence-authorization/v3" or r.get("origin")!="fynix-cyberaudit" or r.get("accepted") is not True or d!=r.get("receipt_digest"): raise Denied("CyberAudit evidence binding invalid")
 ks=keyset
 raw=base64.b64decode(ks.get(r.get("key_id"),""),validate=True); sig=base64.urlsafe_b64decode(r.get("signature","")+"==")
 if len(raw)!=32 or len(sig)!=64: raise Denied("CyberAudit signature invalid")
 with tempfile.TemporaryDirectory() as td:
  pub=Path(td)/"pub.der"; msg=Path(td)/"digest"; sigp=Path(td)/"sig"; pub.write_bytes(bytes.fromhex("302a300506032b6570032100")+raw); msg.write_bytes(bytes.fromhex(d)); sigp.write_bytes(sig)
  p=subprocess.run(["openssl","pkeyutl","-verify","-pubin","-inform","DER","-inkey",str(pub),"-rawin","-in",str(msg),"-sigfile",str(sigp)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  if p.returncode: raise Denied("CyberAudit signature invalid")
 now=datetime.now(timezone.utc); requested=when(r["requested_at"],"Cyber requested"); reviewed=when(r["reviewed_at"],"Cyber reviewed"); observed=when(r["observed_at"],"Cyber observed"); issued=when(r["issued_at"],"Cyber issued"); consumed=when(r["consumed_at"],"Cyber consumed"); expires=when(r["expires_at"],"Cyber expiry"); authority_verified=when(r["authority_binding_verified_at"],"Cyber authority")
 authority={"authority":"executive-hq","company_id":r["company_id"],"customer_id":r["customer_id"],"suite_tenant_id":r["suite_tenant_id"],"verified_at":r["authority_binding_verified_at"],"version":r["authority_binding_version"]}
 if r.get("authority")!="executive-hq" or r.get("authority_binding_digest")!=digest(authority) or not requested<=reviewed<=observed<=issued<=consumed<=now+timedelta(minutes=5) or authority_verified>now+timedelta(minutes=5) or not consumed<expires<=issued+timedelta(seconds=600): raise Denied("CyberAudit evidence chronology invalid")
 return r
def cyber_status(row,expected,authorization_id,require_accepted=True,allow_consumed=False):
 fields={"id","contract_version","profile","request_id","request_digest","status","expires_at","revoked_at","consumed_at","retention_until"}
 if set(row)!=fields or row.get("id")!=authorization_id or row.get("contract_version")!=expected["contract_version"] or row.get("profile")!=expected["profile"] or row.get("request_id")!=expected["request_id"] or row.get("request_digest")!=expected["request_digest"] or row.get("revoked_at") is not None or (row.get("consumed_at") is not None and not allow_consumed): raise Denied("CyberAudit authorization status invalid")
 if row.get("consumed_at") is not None: when(row["consumed_at"],"Cyber consumed")
 if require_accepted and (row.get("status")!="accepted" or when(row["expires_at"],"Cyber authorization expiry")<=datetime.now(timezone.utc)): raise Denied("CyberAudit authorization is not accepted/current")
 return row
def verify_receipt(r,b,operation_id,keyset,change_id):
 expected=set(b)|{"version","origin","change_id","purpose","operation_id","policy_version","authorization_expires_at","authority_binding_verified_at","authority_binding_digest","authority_binding_version","approval_revision","created_by","independent_voters","approved_at","verified_at","issued_at","expires_at","consumed_at","audit_cab_snapshot_digest","claim_nonce","key_id","receipt_digest","signature"}
 if set(r)!=expected: raise Denied("ITSM receipt schema invalid")
 unsigned={k:v for k,v in r.items() if k not in ("receipt_digest","signature")}; d=digest(unsigned); key=keyset.get(r.get("key_id"))
 if r.get("version")!="fynix-change-authorization-receipt/v2" or d!=r.get("receipt_digest") or not key or not hmac.compare_digest(r.get("signature",""),hmac.new(key.encode(),d.encode(),hashlib.sha256).hexdigest()): raise Denied("ITSM receipt signature invalid")
 for k,v in b.items():
  if k!="binding_digest" and r.get(k)!=v: raise Denied("ITSM receipt binding mismatch")
 voters=r.get("independent_voters")
 if r.get("binding_digest")!=b["binding_digest"] or r.get("purpose")!="deploy" or r.get("operation_id")!=operation_id or r.get("change_id")!=change_id or r.get("policy_version")!="fynix-production-deploy/v2" or not isinstance(r.get("authority_binding_version"),int) or r["authority_binding_version"]<1 or not isinstance(voters,list) or not voters or any(not re.fullmatch(r"[a-f0-9]{64}",str(v)) for v in voters): raise Denied("ITSM receipt authority invalid")
 now=datetime.now(timezone.utc); approved=when(r["approved_at"],"approval"); verified=when(r["verified_at"],"verification"); issued=when(r["issued_at"],"claim issued"); consumed=when(r["consumed_at"],"consume"); expires=when(r["expires_at"],"claim expiry"); authorization_expires=when(r["authorization_expires_at"],"authorization expiry")
 if not approved<=verified<=now+timedelta(minutes=5) or not issued<=consumed<=now+timedelta(minutes=5) or not consumed<expires<=issued+timedelta(seconds=600) or consumed>=authorization_expires: raise Denied("ITSM receipt chronology invalid")
 return r
def main():
 p=argparse.ArgumentParser(); p.add_argument("action",choices=("request","status","consume")); p.add_argument("--binding",required=True); p.add_argument("--api-key-file",required=True); p.add_argument("--authorization-id",type=int); p.add_argument("--operation-id"); p.add_argument("--nonce"); p.add_argument("--itsm-keys"); p.add_argument("--cyber-request"); p.add_argument("--cyber-api-key-file"); p.add_argument("--cyber-authorization-id",type=int); p.add_argument("--cyber-nonce"); p.add_argument("--cyber-keys"); a=p.parse_args()
 try:
  body=request(load(a.binding)); itsm_token=locked(a.api_key_file,4096).strip(); call=client(itsm_token)
  if a.action=="request": out=public(call("POST","/change-authorizations",body),body)
  elif a.action=="status": out=public(call("GET",f"/change-authorizations/{a.authorization_id}"),body)
  else:
   if not all((a.authorization_id,a.operation_id,a.nonce,a.itsm_keys,a.cyber_request,a.cyber_api_key_file,a.cyber_authorization_id,a.cyber_nonce,a.cyber_keys)): raise Denied("consume arguments incomplete")
   uuid.UUID(a.operation_id); uuid.UUID(a.nonce); uuid.UUID(a.cyber_nonce); row=public(call("GET",f"/change-authorizations/{a.authorization_id}"),body); expected=verify_cyber_request(load(a.cyber_request),body,row,a.authorization_id,a.operation_id); cyber_token=locked(a.cyber_api_key_file,4096).strip(); ccall=cyber_client(cyber_token); cyber_status(ccall("GET",f"/evidence-authorizations/{a.cyber_authorization_id}"),expected,a.cyber_authorization_id,allow_consumed=True)
   token=claim_token(itsm_token,"fynix-itsm-claim/v2",a.authorization_id,a.nonce,body["binding_digest"]); claim=call("POST",f"/change-authorizations/{a.authorization_id}/claims",{"purpose":"deploy","nonce":a.nonce,"ttl_seconds":600,"binding_digest":body["binding_digest"],"claim_token":token})
   if set(claim)!={"authorization_id","purpose","approval_revision","nonce","issued_at","expires_at"} or claim.get("authorization_id")!=a.authorization_id or claim.get("purpose")!="deploy" or claim.get("nonce")!=a.nonce: raise Denied("ITSM claim invalid")
   issued=when(claim["issued_at"],"claim issued"); expires=when(claim["expires_at"],"claim expiry")
   if not issued<=datetime.now(timezone.utc)+timedelta(minutes=5) or not datetime.now(timezone.utc)<expires<=issued+timedelta(seconds=600): raise Denied("ITSM claim chronology invalid")
   receipt=call("POST",f"/change-authorizations/{a.authorization_id}/consume",{"purpose":"deploy","operation_id":a.operation_id,"binding_digest":body["binding_digest"],"claim_token":token}); itsm_receipt=verify_receipt(receipt,body,a.operation_id,keys(a.itsm_keys),row["change_id"])
   ctoken=claim_token(cyber_token,"fynix-cyberaudit-claim/v3",a.cyber_authorization_id,a.cyber_nonce,expected["request_digest"]); cclaim=ccall("POST",f"/evidence-authorizations/{a.cyber_authorization_id}/claims",{"purpose":"deploy","nonce":a.cyber_nonce,"ttl_seconds":600,"request_digest":expected["request_digest"],"claim_token":ctoken})
   if set(cclaim)!={"authorization_id","purpose","nonce","issued_at","expires_at"} or cclaim.get("authorization_id")!=a.cyber_authorization_id or cclaim.get("nonce")!=a.cyber_nonce: raise Denied("CyberAudit claim invalid")
   ci=when(cclaim["issued_at"],"Cyber claim issued"); ce=when(cclaim["expires_at"],"Cyber claim expiry")
   if not ci<=datetime.now(timezone.utc)+timedelta(minutes=5) or not datetime.now(timezone.utc)<ce<=ci+timedelta(seconds=600): raise Denied("CyberAudit claim chronology invalid")
   cyber_receipt=verify_cyber(ccall("POST",f"/evidence-authorizations/{a.cyber_authorization_id}/consume",{"purpose":"deploy","operation_id":a.operation_id,"request_digest":expected["request_digest"],"claim_token":ctoken}),keys(a.cyber_keys),expected); out={"itsm":itsm_receipt,"cyberaudit":cyber_receipt}
  print(canonical(out)); return 0
 except (Denied,OSError,ValueError,TypeError,subprocess.SubprocessError) as e: print("cyberaudit-deploy-authorization: "+str(e),file=os.sys.stderr); return 2
if __name__=="__main__": raise SystemExit(main())
