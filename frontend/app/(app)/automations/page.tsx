"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, WorkflowItem, WorkflowDetail, WfNodeData, WfEdgeData, WfExecution } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";

const STATUS_COLORS: Record<string, { bg: string; fg: string }> = {
  draft: { bg: "#eef1f2", fg: "#667781" },
  active: { bg: "#e7f7ef", fg: "#0a7d47" },
  paused: { bg: "#fff3cd", fg: "#856404" },
  archived: { bg: "#eef1f2", fg: "#667781" },
  running: { bg: "#cce5ff", fg: "#004085" },
  completed: { bg: "#e7f7ef", fg: "#0a7d47" },
  failed: { bg: "#fdecec", fg: "#c53030" },
  waiting: { bg: "#fff3cd", fg: "#856404" },
};

function StatusBadge({ status }: { status: string }) {
  const c = STATUS_COLORS[status] ?? STATUS_COLORS.draft;
  return <span style={{ background: c.bg, color: c.fg, padding: "2px 9px", borderRadius: 999, fontSize: 12, fontWeight: 600 }}>{status}</span>;
}

const TRIGGER_TYPES = [
  { value: "incoming_message", label: "Incoming message" },
  { value: "new_contact", label: "New contact" },
  { value: "keyword", label: "Keyword match" },
  { value: "tag_added", label: "Tag added" },
  { value: "tag_removed", label: "Tag removed" },
  { value: "scheduled", label: "Scheduled" },
];

const NODE_FAMILIES: { family: WfNodeData["family"]; label: string; color: string; types: { value: string; label: string }[] }[] = [
  {
    family: "trigger", label: "Trigger", color: "#7f66ff",
    types: [
      { value: "incoming_message", label: "Incoming message" },
      { value: "new_contact", label: "New contact" },
      { value: "keyword", label: "Keyword match" },
      { value: "tag_added", label: "Tag added" },
    ],
  },
  {
    family: "action", label: "Action", color: "#25d366",
    types: [
      { value: "send_message", label: "Send message" },
      { value: "send_template", label: "Send template" },
      { value: "add_tag", label: "Add tag" },
      { value: "remove_tag", label: "Remove tag" },
      { value: "assign_agent", label: "Assign agent" },
      { value: "update_contact", label: "Update contact" },
      { value: "webhook", label: "Webhook (HTTP)" },
    ],
  },
  {
    family: "condition", label: "Condition", color: "#007bfc",
    types: [
      { value: "condition_has_tag", label: "Has tag?" },
      { value: "condition_keyword", label: "Keyword match?" },
    ],
  },
  {
    family: "wait", label: "Wait", color: "#ee6723",
    types: [
      { value: "wait", label: "Wait (delay)" },
      { value: "wait_for_reply", label: "Wait for reply" },
    ],
  },
];

const FAMILY_COLORS: Record<string, string> = { trigger: "#7f66ff", action: "#25d366", condition: "#007bfc", wait: "#ee6723" };
const FAMILY_ICONS: Record<string, string> = { trigger: "⚡", action: "▶", condition: "◆", wait: "⏱" };

let nodeCounter = 0;
function genKey() {
  nodeCounter++;
  return `node_${Date.now()}_${nodeCounter}`;
}

type View = "list" | "builder";

export default function AutomationsPage() {
  const [view, setView] = useState<View>("list");
  const [workflows, setWorkflows] = useState<WorkflowItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Create form
  const [showCreate, setShowCreate] = useState(false);
  const [form, setForm] = useState({ name: "", description: "", trigger_type: "incoming_message", keyword: "" });
  const [submitting, setSubmitting] = useState(false);

  // Builder
  const [editingWf, setEditingWf] = useState<WorkflowDetail | null>(null);
  const [nodes, setNodes] = useState<WfNodeData[]>([]);
  const [edges, setEdges] = useState<WfEdgeData[]>([]);
  const [saving, setSaving] = useState(false);
  const [selectedNode, setSelectedNode] = useState<string | null>(null);
  const [showNodePalette, setShowNodePalette] = useState(false);
  const [connectFrom, setConnectFrom] = useState<string | null>(null);

  // Executions
  const [executions, setExecutions] = useState<WfExecution[]>([]);
  const [showExec, setShowExec] = useState(false);

  const loadWorkflows = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.automations.list(token);
      setWorkflows(res.workflows);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadWorkflows(); }, [loadWorkflows]);

  async function createWorkflow(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      const triggerConfig: Record<string, unknown> = {};
      if (form.trigger_type === "keyword" && form.keyword) triggerConfig.keyword = form.keyword;
      await api.automations.create(token, {
        name: form.name,
        description: form.description || undefined,
        trigger_type: form.trigger_type,
        trigger_config: Object.keys(triggerConfig).length ? triggerConfig : undefined,
      });
      setNotice("Workflow created.");
      setShowCreate(false);
      setForm({ name: "", description: "", trigger_type: "incoming_message", keyword: "" });
      loadWorkflows();
    } catch (err) {
      const e = err as ApiError;
      setError(e.errors ? Object.values(e.errors).flat().join(". ") : e.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function openBuilder(id: string) {
    const token = getToken();
    if (!token) return;
    setError(null);
    try {
      const res = await api.automations.get(token, id);
      setEditingWf(res.workflow);
      setNodes(res.workflow.nodes ?? []);
      setEdges(res.workflow.edges ?? []);
      setSelectedNode(null);
      setView("builder");
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function saveCanvas() {
    const token = getToken();
    if (!token || !editingWf) return;
    setSaving(true);
    setError(null);
    try {
      const res = await api.automations.saveCanvas(token, editingWf.id, { nodes, edges });
      setEditingWf(res.workflow);
      setNodes(res.workflow.nodes ?? []);
      setEdges(res.workflow.edges ?? []);
      setNotice("Canvas saved.");
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSaving(false);
    }
  }

  async function activateWorkflow() {
    const token = getToken();
    if (!token || !editingWf) return;
    setError(null);
    try {
      await api.automations.activate(token, editingWf.id);
      setNotice("Workflow activated.");
      const res = await api.automations.get(token, editingWf.id);
      setEditingWf(res.workflow);
      loadWorkflows();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function pauseWorkflow() {
    const token = getToken();
    if (!token || !editingWf) return;
    setError(null);
    try {
      await api.automations.pause(token, editingWf.id);
      setNotice("Workflow paused.");
      const res = await api.automations.get(token, editingWf.id);
      setEditingWf(res.workflow);
      loadWorkflows();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function deleteWorkflow(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      await api.automations.remove(token, id);
      setNotice("Workflow deleted.");
      loadWorkflows();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function loadExecutions() {
    const token = getToken();
    if (!token || !editingWf) return;
    try {
      const res = await api.automations.executions(token, editingWf.id);
      setExecutions(res.executions);
      setShowExec(true);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  function addNode(family: WfNodeData["family"], type: string) {
    const key = genKey();
    const yOffset = nodes.length * 120;
    const newNode: WfNodeData = {
      node_key: key,
      family,
      type,
      config: {},
      position_x: 250,
      position_y: 80 + yOffset,
      is_entry: nodes.length === 0 && family === "trigger",
    };
    setNodes((prev) => [...prev, newNode]);
    setShowNodePalette(false);

    if (connectFrom) {
      setEdges((prev) => [...prev, { source_node_key: connectFrom, target_node_key: key }]);
      setConnectFrom(null);
    }
  }

  function removeNode(key: string) {
    setNodes((prev) => prev.filter((n) => n.node_key !== key));
    setEdges((prev) => prev.filter((e) => e.source_node_key !== key && e.target_node_key !== key));
    if (selectedNode === key) setSelectedNode(null);
  }

  function removeEdge(src: string, tgt: string) {
    setEdges((prev) => prev.filter((e) => !(e.source_node_key === src && e.target_node_key === tgt)));
  }

  function setEdgeBranch(src: string, tgt: string, branch: string) {
    setEdges((prev) => prev.map((e) => (e.source_node_key === src && e.target_node_key === tgt ? { ...e, branch } : e)));
  }

  function updateNodeConfig(key: string, config: Record<string, unknown>) {
    setNodes((prev) => prev.map((n) => n.node_key === key ? { ...n, config } : n));
  }

  function setEntryNode(key: string) {
    setNodes((prev) => prev.map((n) => ({ ...n, is_entry: n.node_key === key })));
  }

  const canEdit = editingWf?.status === "draft" || editingWf?.status === "paused";
  const selNode = nodes.find((n) => n.node_key === selectedNode);

  // --- LIST VIEW ---
  if (view === "list") {
    return (
      <>
        <PageHeader
          title="Automations"
          subtitle="Visual workflow builder — triggers, conditions, actions"
          action={
            <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={() => { setShowCreate((v) => !v); setError(null); }}>
              + New Workflow
            </button>
          }
        />
        {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47" }}>{notice}</div>}
        {error && <div className="error">{error}</div>}

        {showCreate && (
          <div className="panel">
            <h2>Create Workflow</h2>
            <form onSubmit={createWorkflow}>
              <div className="field">
                <label>Name</label>
                <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Welcome new contacts" />
              </div>
              <div className="field">
                <label>Description (optional)</label>
                <input value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} placeholder="Sends welcome message when a new contact arrives" />
              </div>
              <div className="field">
                <label>Trigger type</label>
                <select value={form.trigger_type} onChange={(e) => setForm((f) => ({ ...f, trigger_type: e.target.value }))} style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
                  {TRIGGER_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              {form.trigger_type === "keyword" && (
                <div className="field">
                  <label>Keyword</label>
                  <input value={form.keyword} onChange={(e) => setForm((f) => ({ ...f, keyword: e.target.value }))} placeholder="hello" />
                </div>
              )}
              <div style={{ display: "flex", gap: 10 }}>
                <button className="btn" disabled={submitting}>{submitting ? "Creating..." : "Create"}</button>
                <button type="button" className="btn" style={{ background: "#888" }} onClick={() => setShowCreate(false)}>Cancel</button>
              </div>
            </form>
          </div>
        )}

        <div className="panel">
          {loading ? (
            <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
          ) : workflows.length === 0 ? (
            <p className="muted">No workflows yet. Click "+ New Workflow" to create one.</p>
          ) : (
            <div style={{ overflowX: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                <thead>
                  <tr style={{ borderBottom: "2px solid var(--border)" }}>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Name</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Trigger</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Status</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}>Nodes</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}>Runs</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Created</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}></th>
                  </tr>
                </thead>
                <tbody>
                  {workflows.map((wf) => (
                    <tr key={wf.id} style={{ borderBottom: "1px solid var(--border)" }}>
                      <td style={{ padding: "8px 4px", fontWeight: 500, cursor: "pointer", color: "#1a7f64" }} onClick={() => openBuilder(wf.id)}>{wf.name}</td>
                      <td style={{ padding: "8px 4px" }}>{TRIGGER_TYPES.find((t) => t.value === wf.trigger_type)?.label ?? wf.trigger_type}</td>
                      <td style={{ padding: "8px 4px" }}><StatusBadge status={wf.status} /></td>
                      <td style={{ padding: "8px 4px", textAlign: "right" }}>{wf.nodes_count ?? 0}</td>
                      <td style={{ padding: "8px 4px", textAlign: "right" }}>{wf.executions_count ?? 0}</td>
                      <td style={{ padding: "8px 4px" }}>{wf.created_at ? new Date(wf.created_at).toLocaleDateString("en-IN") : "—"}</td>
                      <td style={{ padding: "8px 4px", textAlign: "right" }}>
                        <div style={{ display: "flex", gap: 6, justifyContent: "flex-end" }}>
                          <button className="btn-mini" onClick={() => openBuilder(wf.id)}>Edit</button>
                          {wf.status === "draft" && <button className="btn-mini danger" onClick={() => deleteWorkflow(wf.id)}>Delete</button>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </>
    );
  }

  // --- BUILDER VIEW ---
  return (
    <>
      <PageHeader
        title={editingWf?.name ?? "Workflow Builder"}
        subtitle={editingWf ? `${TRIGGER_TYPES.find((t) => t.value === editingWf.trigger_type)?.label ?? editingWf.trigger_type} · ${editingWf.status}` : ""}
        action={
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            {canEdit && (
              <>
                <button className="btn" style={{ width: "auto", padding: "8px 16px", background: "#555" }} onClick={() => setShowNodePalette((v) => !v)}>
                  + Add Node
                </button>
                <button className="btn" style={{ width: "auto", padding: "8px 16px" }} disabled={saving} onClick={saveCanvas}>
                  {saving ? "Saving..." : "Save"}
                </button>
              </>
            )}
            {(editingWf?.status === "draft" || editingWf?.status === "paused") && (
              <button className="btn" style={{ width: "auto", padding: "8px 16px", background: "#0a7d47" }} onClick={activateWorkflow}>Activate</button>
            )}
            {editingWf?.status === "active" && (
              <button className="btn" style={{ width: "auto", padding: "8px 16px", background: "#856404" }} onClick={pauseWorkflow}>Pause</button>
            )}
            <button className="btn-mini" onClick={loadExecutions}>Runs</button>
            <button className="btn-mini" onClick={() => { setView("list"); setEditingWf(null); setShowExec(false); }}>Back</button>
          </div>
        }
      />
      {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47" }}>{notice}</div>}
      {error && <div className="error">{error}</div>}

      {/* Node palette */}
      {showNodePalette && canEdit && (
        <div className="panel" style={{ marginBottom: 16 }}>
          <h3 style={{ fontSize: 14, marginBottom: 10 }}>Add a node</h3>
          <div style={{ display: "flex", gap: 16, flexWrap: "wrap" }}>
            {NODE_FAMILIES.map((fam) => (
              <div key={fam.family} style={{ minWidth: 160 }}>
                <div style={{ fontSize: 12, fontWeight: 700, color: fam.color, marginBottom: 6, textTransform: "uppercase" }}>{fam.label}</div>
                {fam.types.map((t) => (
                  <div
                    key={t.value}
                    onClick={() => addNode(fam.family, t.value)}
                    style={{ padding: "6px 10px", cursor: "pointer", fontSize: 13, borderRadius: 6, marginBottom: 2, transition: "background .1s" }}
                    onMouseOver={(e) => (e.currentTarget.style.background = "#f0f2f5")}
                    onMouseOut={(e) => (e.currentTarget.style.background = "transparent")}
                  >
                    {FAMILY_ICONS[fam.family]} {t.label}
                  </div>
                ))}
              </div>
            ))}
          </div>
        </div>
      )}

      <div style={{ display: "flex", gap: 16, flexWrap: "wrap" }}>
        {/* Canvas */}
        <div className="panel" style={{ flex: 2, minWidth: 400, minHeight: 400 }}>
          <h3 style={{ fontSize: 14, marginBottom: 12 }}>Flow canvas ({nodes.length} nodes, {edges.length} connections)</h3>
          {nodes.length === 0 ? (
            <div style={{ padding: 40, textAlign: "center", color: "var(--muted)" }}>
              No nodes yet. Click "+ Add Node" to build your workflow.
            </div>
          ) : (
            <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
              {nodes.map((node, i) => {
                const color = FAMILY_COLORS[node.family] ?? "#888";
                const icon = FAMILY_ICONS[node.family] ?? "●";
                const isSelected = selectedNode === node.node_key;
                const outEdges = edges.filter((e) => e.source_node_key === node.node_key);
                const inEdges = edges.filter((e) => e.target_node_key === node.node_key);
                return (
                  <div key={node.node_key}>
                    {/* Incoming connections */}
                    {inEdges.length > 0 && (
                      <div style={{ textAlign: "center", color: "var(--muted)", fontSize: 11, marginBottom: 2 }}>
                        {inEdges.map((e) => {
                          const srcNode = nodes.find((n) => n.node_key === e.source_node_key);
                          return (
                            <span key={e.source_node_key} style={{ marginRight: 8 }}>
                              ↑ from {srcNode?.type ?? e.source_node_key}
                              {e.branch && <span style={{ color: "#007bfc" }}> [{e.branch}]</span>}
                            </span>
                          );
                        })}
                      </div>
                    )}
                    <div
                      onClick={() => setSelectedNode(isSelected ? null : node.node_key)}
                      style={{
                        display: "flex", alignItems: "center", gap: 12, padding: "12px 16px",
                        border: `2px solid ${isSelected ? color : "var(--border)"}`,
                        borderRadius: 12, cursor: "pointer", transition: "border-color .15s",
                        background: isSelected ? color + "08" : "#fff",
                      }}
                    >
                      <div style={{ width: 36, height: 36, borderRadius: 8, background: color, color: "#fff", display: "grid", placeItems: "center", fontSize: 18, flexShrink: 0 }}>
                        {icon}
                      </div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 13, fontWeight: 600 }}>{node.type.replace(/_/g, " ")}</div>
                        <div style={{ fontSize: 11, color: "var(--muted)" }}>
                          {node.family} {node.is_entry ? "· entry" : ""} · {node.node_key.slice(-8)}
                        </div>
                      </div>
                      {canEdit && (
                        <div style={{ display: "flex", gap: 4 }}>
                          <button
                            className="btn-mini"
                            onClick={(e) => { e.stopPropagation(); setConnectFrom(node.node_key); setShowNodePalette(true); }}
                            title="Connect to new node"
                          >→</button>
                          <button
                            className="btn-mini danger"
                            onClick={(e) => { e.stopPropagation(); removeNode(node.node_key); }}
                          >✕</button>
                        </div>
                      )}
                    </div>
                    {/* Outgoing connections */}
                    {outEdges.length > 0 && (
                      <div style={{ textAlign: "center", fontSize: 18, color: color, margin: "2px 0" }}>↓</div>
                    )}
                  </div>
                );
              })}
            </div>
          )}

          {/* Manual connect */}
          {canEdit && nodes.length >= 2 && (
            <div style={{ marginTop: 16, paddingTop: 12, borderTop: "1px solid var(--border)" }}>
              <h4 style={{ fontSize: 12, color: "var(--muted)", marginBottom: 8 }}>Connect nodes</h4>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                <select
                  value={connectFrom ?? ""}
                  onChange={(e) => setConnectFrom(e.target.value || null)}
                  style={{ padding: "6px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                >
                  <option value="">From...</option>
                  {nodes.map((n) => <option key={n.node_key} value={n.node_key}>{n.type} ({n.node_key.slice(-6)})</option>)}
                </select>
                <span style={{ fontSize: 16 }}>→</span>
                <select
                  onChange={(e) => {
                    if (connectFrom && e.target.value) {
                      setEdges((prev) => [...prev, { source_node_key: connectFrom, target_node_key: e.target.value }]);
                      setConnectFrom(null);
                      e.target.value = "";
                    }
                  }}
                  style={{ padding: "6px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                >
                  <option value="">To...</option>
                  {nodes.filter((n) => n.node_key !== connectFrom).map((n) => (
                    <option key={n.node_key} value={n.node_key}>{n.type} ({n.node_key.slice(-6)})</option>
                  ))}
                </select>
              </div>
            </div>
          )}
        </div>

        {/* Node config panel */}
        <div className="panel" style={{ flex: 1, minWidth: 280 }}>
          {selNode ? (
            <>
              <h3 style={{ fontSize: 14, marginBottom: 8 }}>Node: {selNode.type.replace(/_/g, " ")}</h3>
              <div style={{ fontSize: 12, color: "var(--muted)", marginBottom: 12 }}>
                Family: {selNode.family} · Key: {selNode.node_key.slice(-8)}
              </div>

              {canEdit && (
                <>
                  <div className="field">
                    <label style={{ fontSize: 12 }}>Entry node</label>
                    <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 13, cursor: "pointer" }}>
                      <input type="checkbox" checked={!!selNode.is_entry} onChange={() => setEntryNode(selNode.node_key)} />
                      This is the starting node
                    </label>
                  </div>

                  {/* Config fields based on type */}
                  {(selNode.type === "send_message") && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Message text</label>
                      <textarea
                        value={(selNode.config?.message as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, message: e.target.value })}
                        rows={3}
                        placeholder="Hello! Welcome..."
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13, resize: "vertical" }}
                      />
                    </div>
                  )}

                  {(selNode.type === "send_template") && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Template name</label>
                      <input
                        value={(selNode.config?.template_name as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, template_name: e.target.value })}
                        placeholder="test_welcome"
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {(selNode.type === "add_tag" || selNode.type === "remove_tag") && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Tag name</label>
                      <input
                        value={(selNode.config?.tag as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, tag: e.target.value })}
                        placeholder="vip"
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {selNode.type === "wait" && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Wait duration (minutes)</label>
                      <input
                        type="number"
                        value={(selNode.config?.minutes as number) ?? 5}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, minutes: parseInt(e.target.value) || 5 })}
                        min={1}
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {selNode.type === "condition_keyword" && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Keyword to match</label>
                      <input
                        value={(selNode.config?.keyword as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, keyword: e.target.value })}
                        placeholder="yes"
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {selNode.type === "condition_has_tag" && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Tag to check</label>
                      <input
                        value={(selNode.config?.tag as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, tag: e.target.value })}
                        placeholder="vip"
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {selNode.type === "webhook" && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Webhook URL</label>
                      <input
                        value={(selNode.config?.url as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, url: e.target.value })}
                        placeholder="https://..."
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {selNode.type === "assign_agent" && (
                    <div className="field">
                      <label style={{ fontSize: 12 }}>Agent email</label>
                      <input
                        value={(selNode.config?.agent_email as string) ?? ""}
                        onChange={(e) => updateNodeConfig(selNode.node_key, { ...selNode.config, agent_email: e.target.value })}
                        placeholder="agent@company.com"
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}
                      />
                    </div>
                  )}

                  {selNode.type === "send_message" && (
                    <p className="muted" style={{ fontSize: 11, marginTop: -4 }}>
                      Tip: use {"{{name}}"} and {"{{phone}}"} to personalise. Free-text only sends inside the 24-hour window — use a template otherwise.
                    </p>
                  )}
                </>
              )}

              {/* Connections from this node */}
              <div style={{ marginTop: 16 }}>
                <h4 style={{ fontSize: 12, color: "var(--muted)", marginBottom: 6 }}>Connections from this node</h4>
                {selNode.family === "condition" && edges.some((e) => e.source_node_key === selNode.node_key) && (
                  <p className="muted" style={{ fontSize: 11, marginBottom: 6 }}>
                    Pick <b>Yes</b> / <b>No</b> for each path. If you leave them as “default”, the condition acts as a gate — only a TRUE result continues.
                  </p>
                )}
                {edges.filter((e) => e.source_node_key === selNode.node_key).map((e) => {
                  const tgt = nodes.find((n) => n.node_key === e.target_node_key);
                  return (
                    <div key={e.target_node_key} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, marginBottom: 4 }}>
                      <span>→ {tgt?.type ?? e.target_node_key}</span>
                      {canEdit && selNode.family === "condition" ? (
                        <select
                          value={e.branch ?? "default"}
                          onChange={(ev) => setEdgeBranch(e.source_node_key, e.target_node_key, ev.target.value)}
                          style={{ padding: "2px 6px", border: "1px solid var(--border)", borderRadius: 6, fontSize: 11 }}
                        >
                          <option value="default">default</option>
                          <option value="yes">Yes (true)</option>
                          <option value="no">No (false)</option>
                        </select>
                      ) : (
                        e.branch && e.branch !== "default" && <span style={{ color: "#007bfc", fontSize: 11 }}>[{e.branch}]</span>
                      )}
                      {canEdit && <button className="btn-mini danger" style={{ padding: "1px 6px", fontSize: 10 }} onClick={() => removeEdge(e.source_node_key, e.target_node_key)}>✕</button>}
                    </div>
                  );
                })}
                {edges.filter((e) => e.source_node_key === selNode.node_key).length === 0 && (
                  <p className="muted" style={{ fontSize: 12 }}>No outgoing connections</p>
                )}
              </div>
            </>
          ) : (
            <div style={{ padding: 20, textAlign: "center", color: "var(--muted)", fontSize: 13 }}>
              Click a node to configure it
            </div>
          )}
        </div>
      </div>

      {/* Executions modal */}
      {showExec && (
        <div className="panel" style={{ marginTop: 16 }}>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
            <h2>Execution history</h2>
            <button className="btn-mini" onClick={() => setShowExec(false)}>Close</button>
          </div>
          {executions.length === 0 ? (
            <p className="muted">No executions yet.</p>
          ) : (
            <div style={{ overflowX: "auto", marginTop: 8 }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                <thead>
                  <tr style={{ borderBottom: "2px solid var(--border)" }}>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Contact</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Status</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Current node</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Started</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Finished</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Error</th>
                  </tr>
                </thead>
                <tbody>
                  {executions.map((ex) => (
                    <tr key={ex.id} style={{ borderBottom: "1px solid var(--border)" }}>
                      <td style={{ padding: "6px 4px" }}>{ex.contact?.name || ex.contact?.phone || "—"}</td>
                      <td style={{ padding: "6px 4px" }}><StatusBadge status={ex.status} /></td>
                      <td style={{ padding: "6px 4px", fontFamily: "monospace", fontSize: 11 }}>{ex.current_node_key || "—"}</td>
                      <td style={{ padding: "6px 4px" }}>{ex.started_at ? new Date(ex.started_at).toLocaleString("en-IN") : "—"}</td>
                      <td style={{ padding: "6px 4px" }}>{ex.finished_at ? new Date(ex.finished_at).toLocaleString("en-IN") : "—"}</td>
                      <td style={{ padding: "6px 4px", color: "var(--danger)", fontSize: 11 }}>{ex.error_message || "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </>
  );
}
