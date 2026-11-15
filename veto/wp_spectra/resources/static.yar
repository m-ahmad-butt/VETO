// VETO educational indicators, not malware-family attribution signatures.
rule VETO_Encoded_Evaluation {
    meta:
        rule_id = "YARA001"
        severity = "high"
        title = "Encoded data passed to evaluation"
        rationale = "An eval/decode chain can conceal executable PHP."
        limitation = "Raw-byte pattern; comments, strings, or legitimate loaders can match."
    strings:
        $chain = /eval[ \t\r\n]*\([ \t\r\n]*(base64_decode|gzinflate|gzuncompress)[ \t\r\n]*\(/ nocase
    condition:
        $chain
}

rule VETO_Request_Evaluation {
    meta:
        rule_id = "YARA002"
        severity = "high"
        title = "Request data passed to evaluation"
        rationale = "Direct request-to-eval syntax can permit arbitrary code execution."
        limitation = "Raw-byte pattern; reachability and input validation are not established."
    strings:
        $request_eval = /eval[ \t\r\n]*\([ \t\r\n]*\$_(POST|GET|REQUEST|COOKIE)[ \t\r\n]*\[/ nocase
    condition:
        $request_eval
}
