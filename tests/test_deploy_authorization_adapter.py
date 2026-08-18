import importlib.util,json,unittest,uuid
from datetime import datetime,timezone,timedelta
from pathlib import Path
P=Path(__file__).parents[1]/"scripts/validate-cyberaudit-deploy-authorization.py"
S=importlib.util.spec_from_file_location("deploy_auth",P); M=importlib.util.module_from_spec(S); S.loader.exec_module(M)
def binding():
 return {"company_id":1,"suite_tenant_id":str(uuid.uuid4()),"customer_id":str(uuid.uuid4()),"request_id":str(uuid.uuid4()),"release_sha":"1"*40,"image_digest":"sha256:"+"2"*64,"artifact_sha256":"3"*64,"manifest_sha256":"4"*64,"previous_release_sha":"5"*40,"previous_image_digest":"sha256:"+"6"*64,"previous_artifact_sha256":"7"*64,"rollback_ref":"fynix-release:"+"5"*40+"@sha256:"+"6"*64+"#sha256:"+"7"*64,"soak_receipt_sha256":"8"*64,"soak_evidence_sha256":"9"*64,"readiness_evidence_sha256":"a"*64}
def cyber_request(body,row,auth_id,op):
 v={k:x for k,x in body.items() if k not in {"contract_version","binding_digest"}};v.update({"contract_version":"fynix-cyberaudit-evidence-authorization-request/v3","purpose":"deploy","operation_id":op,"policy_version":"fynix-production-deploy/v2","itsm_change_id":row["change_id"],"itsm_authorization_id":auth_id,"itsm_approval_revision":row["approval_revision"],"itsm_binding_digest":body["binding_digest"]});v["request_digest"]=M.digest(v);return v
class AdapterTest(unittest.TestCase):
 def test_closed_request_binds_full_release_and_rollback_tuple(self):
  b=M.request(binding()); self.assertEqual(b["profile"],M.PROFILE); self.assertEqual(b["binding_digest"],M.digest({k:v for k,v in b.items() if k!="binding_digest"}))
  bad=dict(binding()); bad["rollback_ref"]="fynix-release:wrong"
  with self.assertRaisesRegex(M.Denied,"binding"): M.request(bad)
 def test_locked_files_reject_symlink_and_group_access(self):
  import tempfile
  with tempfile.TemporaryDirectory() as td:
   p=Path(td)/"key"; p.write_text("x"*32); p.chmod(0o640)
   with self.assertRaises(M.Denied): M.locked(p)
   p.chmod(0o600); q=Path(td)/"link"; q.symlink_to(p)
   with self.assertRaises(M.Denied): M.locked(q)
 def test_transport_is_pinned_and_closed(self):
  with self.assertRaisesRegex(M.Denied,"path"): M.client("x"*32)("GET","/changes/1")
 def test_cyber_v3_request_exactly_cross_binds_itsm_release(self):
  b=M.request(binding());op=str(uuid.uuid4());row={"change_id":4,"approval_revision":1};v=cyber_request(b,row,3,op);self.assertEqual(M.verify_cyber_request(v,b,row,3,op),v)
  v["artifact_sha256"]="f"*64;v["request_digest"]=M.digest({k:x for k,x in v.items() if k!="request_digest"})
  with self.assertRaisesRegex(M.Denied,"differs"):M.verify_cyber_request(v,b,row,3,op)
 def test_public_status_is_closed_and_contract_version_bound(self):
  now=datetime.now(timezone.utc); b=M.request(binding()); row={**b,"id":1,"change_id":2,"policy_version":"fynix-production-deploy/v2","approval_revision":0,"revoked":False,"created_at":now.isoformat(),"expires_at":(now+timedelta(hours=1)).isoformat()}; M.public(row,b)
  row["extra"]=True
  with self.assertRaisesRegex(M.Denied,"schema"): M.public(row,b)
 def test_timestamp_parser_rejects_naive_and_malformed(self):
  for value in (None,"nonsense","2026-01-01T00:00:00"):
   with self.assertRaises(M.Denied): M.when(value,"test")
if __name__=="__main__": unittest.main()
