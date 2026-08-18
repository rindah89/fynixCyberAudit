import importlib.util,json,tempfile,unittest,uuid
from pathlib import Path
P=Path(__file__).parents[1]/"scripts/persist-release-receipts.py";S=importlib.util.spec_from_file_location("persist",P);M=importlib.util.module_from_spec(S);S.loader.exec_module(M)
class PersistenceTest(unittest.TestCase):
 def test_atomic_idempotent_receipts_survive_and_exclude_tokens(self):
  with tempfile.TemporaryDirectory() as td:
   root=Path(td);root.chmod(0o700);op=str(uuid.uuid4());itsm={"operation_id":op,"receipt_digest":"a"*64};cyber={"operation_id":op,"receipt_digest":"b"*64};binding={"binding_digest":"c"*64}
   first=M.persist(root,op,itsm,cyber,binding);self.assertEqual(first,M.persist(root,op,itsm,cyber,binding));self.assertEqual(len(list(root.iterdir())),4)
   for p in root.iterdir(): self.assertEqual(p.stat().st_mode&0o777,0o600)
   with self.assertRaisesRegex(M.Error,"token"): M.persist(root,str(uuid.uuid4()),{"operation_id":"x","claim_token":"secret"},cyber,binding)
 def test_conflicting_replay_and_unsafe_directory_deny(self):
  with tempfile.TemporaryDirectory() as td:
   root=Path(td);root.chmod(0o700);op=str(uuid.uuid4());itsm={"operation_id":op,"receipt_digest":"a"*64};cyber={"operation_id":op,"receipt_digest":"b"*64};binding={"binding_digest":"c"*64};M.persist(root,op,itsm,cyber,binding);itsm["receipt_digest"]="d"*64
   with self.assertRaisesRegex(M.Error,"conflicts"): M.persist(root,op,itsm,cyber,binding)
   root.chmod(0o755)
   with self.assertRaisesRegex(M.Error,"owner-only"): M.persist(root,str(uuid.uuid4()),itsm,cyber,binding)
if __name__=="__main__":unittest.main()
