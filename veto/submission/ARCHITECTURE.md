# Delivered architecture and trust boundaries

```mermaid
flowchart TD
    U[Administrator: local browser or CLI] --> I[Untrusted plugin ZIP]
    I --> A[Private bounded ZIP intake]
    A --> S[PHP lexical rules / YARA / package anomalies / hashes]
    S --> E[Versioned static evidence]
    A --> D[Trusted host Docker controller]
    subgraph Disposable WordPress sandbox
        W[Non-root WordPress + submitted copy]
        M[Fresh MySQL]
        W --> M
    end
    D --> W
    subgraph Separate observation boundary
        O[File observer / direct SQL snapshots / SQL audit]
        N[DNS and controlled HTTP sinks / HTTP telemetry]
    end
    W --> N
    M --> O
    W --> O
    O --> R[Retained runtime evidence outside sandbox]
    N --> R
    E --> V[Investigation: reasons / timeline / before-after / controls]
    R --> V
    V --> P[Immutable PDF + JSON snapshot]
    D --> C[Ownership-checked cleanup and independent resource listing]
    R --> Q[Evaluation: fixture assertions + separate direct reads]
    Q --> T[Saved ground truth + specific event matching + metric replay]
```

The host and Docker daemon are trusted; submitted names and code are untrusted.
Containers share a kernel. The submitted container has no host source bind mount
or Docker socket. Ground truth is separate from monitor events but relies on
trusted authored fixture assertions for transient/network operations. Reports
contain untrusted text rendered safely. The local single-user interface is not
a production multi-user service. Legacy web/ lab infrastructure is excluded.
