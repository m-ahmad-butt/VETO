"""Measurement contracts: mistakes here would invalidate the academic claims."""
import copy
import unittest
from tools.evaluate_phase4 import classification, confirmed, matching, ratio, report_preserved


class EvaluationTests(unittest.TestCase):
    def test_undefined_denominator_is_null(self):
        self.assertIsNone(ratio(0,0)['percent'])
        with self.assertRaises(ValueError):ratio(2,1)

    def test_confusion_matrix_and_incomplete_are_separate(self):
        rows=[{'label':label,'flagged':flag,'status':'completed'} for label,flag in
              [('suspicious',True),('suspicious',False),('benign',True),('benign',False)]]
        rows.append({'label':'suspicious','flagged':False,'status':'failed'})
        result=classification(rows)
        self.assertEqual([result[k] for k in ('tp','fp','tn','fn','incomplete')],[1,1,1,1,1])
        self.assertEqual(result['precision']['value'],0.5)
        self.assertEqual(result['recall']['value'],0.5)

    def test_receipt_alone_cannot_confirm_persistent_effect(self):
        witness={'method':'independent reads','receipts':{'database_write':{'ok':True}},'options':{}}
        self.assertFalse(confirmed('database_write',witness))
        witness['options']['phase4_value']='phase4-confirmed'
        self.assertTrue(confirmed('database_write',witness))
        witness['error']='SQL unavailable'
        self.assertFalse(confirmed('database_write',witness))

    def test_unrelated_network_event_does_not_count_as_detected(self):
        event={'category':'network','action':'dns_blocked','resource':'other.example.invalid','collector':'dns-sink','evidence':[],'after':None}
        self.assertFalse(matching('dns_attempt',event))
        event['resource']='phase4-dns.example.invalid'
        self.assertTrue(matching('dns_attempt',event))
        self.assertFalse(matching('direct_ip_attempt',event))

    def test_completeness_requires_exact_json_content_and_pdf_index(self):
        event={'event_id':'R000001','category':'file','action':'created','resource':'x','before':None,'after':'abc','evidence':[{'id':'e','text':'proof'}]}
        record={**event,'source':'runtime','record_id':'R:R000001'}
        export={'records':[record],'artifacts':{'dynamic.json':{'runtime_events':[event]}}}
        self.assertTrue(report_preserved(event,export,'R:R000001'))
        self.assertFalse(report_preserved(event,export,''))
        changed=copy.deepcopy(export);changed['records'][0]['after']='wrong'
        self.assertFalse(report_preserved(event,changed,'R:R000001'))
        duplicated=copy.deepcopy(export);duplicated['records'].append(record)
        self.assertFalse(report_preserved(event,duplicated,'R:R000001'))


if __name__=='__main__':unittest.main()
