export type SubmissionStatus =
    | 'processing'
    | 'pending_review'
    | 'approved'
    | 'rejected';

export type DecisionSource =
    | 'blocklist'
    | 'policy'
    | 'moderator'
    | 'appeal'
    | 'audit';

export type Outcome = 'approve' | 'review' | 'reject';

export type TaskKind = 'triage' | 'escalation' | 'appeal' | 'audit';

export type StepStatus = 'running' | 'succeeded' | 'skipped' | 'failed';

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type SubmissionSummary = {
    id: number;
    caption: string | null;
    status: SubmissionStatus;
    has_image: boolean;
    thumbnail_url: string | null;
    decision_source: DecisionSource | null;
    decision_reason: string | null;
    created_at: string;
    decided_at: string | null;
    appealed: boolean;
    uploader: string | null;
    top_category: { value: string; label: string; score: number } | null;
};

export type PipelineStep = {
    id: number;
    stage: 'prepare' | 'match_known' | 'classify' | 'apply_policy';
    label: string;
    status: StepStatus;
    attempt: number;
    duration_ms: number | null;
    started_at: string;
    detail: Record<string, unknown> | null;
    error: string | null;
};

export type CategoryScore = {
    value: string;
    label: string;
    score: number;
    evidence: string;
    review: number;
    reject: number | null;
};

export type ReviewTask = {
    id: number;
    kind: TaskKind;
    kind_label: string;
    priority: number;
    automated_outcome: Outcome | null;
    note: string | null;
    claimed_by: string | null;
    claim_expires_at: string | null;
    decision: 'approve' | 'reject' | 'escalate' | null;
    reason: string | null;
    decision_note: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    created_at: string;
};

export type SubmissionDetail = SubmissionSummary & {
    image_url: string | null;
    width: number | null;
    height: number | null;
    had_gps: boolean;
    appeal_message: string | null;
    appealed_at: string | null;
    can_appeal: boolean;
    appeal_deadline: string | null;
    steps: PipelineStep[];
    // Staff only
    ai?: {
        model: string | null;
        summary: string | null;
        error: string | null;
        categories: CategoryScore[];
    } | null;
    policy_outcome?: Outcome | null;
    policy_version?: number | null;
    decided_by?: string | null;
    sha256?: string | null;
    dhash?: string | null;
    uploader_history?: { total: number; rejected: number };
    tasks?: ReviewTask[];
};

export type EvaluationStatus = 'pending' | 'running' | 'completed' | 'failed';

export type EvaluationRun = {
    id: number;
    model: string;
    effort: string;
    sample_size: number;
    status: EvaluationStatus;
    error: string | null;
    items: number;
    scored: number;
    policy_version: number | null;
    created_by: string | null;
    created_at: string;
    completed_at: string | null;
};

export type EvaluationSide = {
    model: string | null;
    outcomes: Record<'approve' | 'review' | 'reject', number>;
    correct: number;
    missed: number;
    wrongful: number;
    accuracy: number | null;
    cost_per_item: number | null;
};

export type EvaluationReport = {
    scored: number;
    errored: number;
    current: EvaluationSide;
    candidate: EvaluationSide;
    changed: number;
    drift: { category: string; label: string; mean_change: number }[];
    examples: {
        id: number;
        human: string;
        current: string;
        candidate: string;
        caption: string | null;
    }[];
};
