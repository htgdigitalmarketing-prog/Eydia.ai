// Thin fetch wrapper around the Laravel API. Every response uses the
// { success, data } / { success, message, errors } envelope from the backend.

const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1";

export type ApiError = {
  message: string;
  errors?: Record<string, string[]>;
  status: number;
};

async function request<T>(
  path: string,
  options: { method?: string; body?: unknown; token?: string | null } = {}
): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    method: options.method ?? "GET",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(options.token ? { Authorization: `Bearer ${options.token}` } : {}),
    },
    body: options.body ? JSON.stringify(options.body) : undefined,
    cache: "no-store",
  });

  let json: any = null;
  try {
    json = await res.json();
  } catch {
    // non-JSON response
  }

  if (!res.ok || (json && json.success === false)) {
    const err: ApiError = {
      message: json?.message ?? `Request failed (${res.status})`,
      errors: json?.errors,
      status: res.status,
    };
    throw err;
  }

  return json.data as T;
}

export const api = {
  register: (body: {
    company_name: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => request<AuthPayload>("/auth/register", { method: "POST", body }),

  login: (body: { email: string; password: string }) =>
    request<AuthPayload>("/auth/login", { method: "POST", body }),

  requestOtp: (body: { phone: string }) =>
    request<{ message: string; dev_code?: string }>("/auth/otp/request", { method: "POST", body }),

  verifyOtp: (body: { phone: string; code: string }) =>
    request<AuthPayload>("/auth/otp/verify", { method: "POST", body }),

  me: (token: string) => request<{ user: User }>("/auth/me", { token }),

  updateProfile: (token: string, body: { name?: string; email?: string; phone?: string; address?: string; avatar_url?: string | null }) =>
    request<{ user: User }>("/auth/profile", { method: "PUT", body, token }),

  changePassword: (token: string, body: { current_password?: string; password: string; password_confirmation: string }) =>
    request<{ message: string }>("/auth/change-password", { method: "POST", body, token }),

  ping: (token: string) => request<{ pong: boolean; tenant_id: number }>("/ping", { token }),

  // Public pricing for the marketing landing page (no auth).
  publicPlans: () => request<{ plans: PublicPlan[] }>("/public/plans"),

  health: () => request<{ status: string }>("/health"),

  whatsapp: {
    config: (token: string) => request<WaConfig>("/whatsapp/config", { token }),
    numbers: (token: string) => request<{ numbers: WaNumber[] }>("/whatsapp/numbers", { token }),
    connectManual: (
      token: string,
      body: {
        waba_id: string;
        phone_number_id: string;
        display_phone_number: string;
        access_token: string;
        name?: string;
      }
    ) => request<{ number: WaNumber }>("/whatsapp/connect-manual", { method: "POST", body, token }),
    embeddedSignup: (
      token: string,
      body: { code: string; waba_id: string; phone_number_id: string | null }
    ) => request<{ account: unknown }>("/whatsapp/embedded-signup", { method: "POST", body, token }),
    sync: (token: string, id: string) =>
      request<{ number: WaNumber }>(`/whatsapp/numbers/${id}/sync`, { method: "POST", token }),
    sendTest: (
      token: string,
      id: string,
      body: { to: string; type?: "template" | "text"; body?: string; template?: string; language?: string }
    ) => request<{ wamid: string | null; to: string }>(`/whatsapp/numbers/${id}/send-test`, { method: "POST", body, token }),
    register: (token: string, id: string, pin: string) =>
      request<{ number: WaNumber }>(`/whatsapp/numbers/${id}/register`, { method: "POST", body: { pin }, token }),
    subscribeApp: (token: string, id: string) =>
      request<{ message: string }>(`/whatsapp/numbers/${id}/subscribe-app`, { method: "POST", token }),
    updateToken: (token: string, id: string, access_token: string) =>
      request<{ message: string }>(`/whatsapp/numbers/${id}/token`, { method: "PUT", body: { access_token }, token }),
    disconnect: (token: string, id: string) =>
      request<{ message: string }>(`/whatsapp/numbers/${id}`, { method: "DELETE", token }),
  },

  contacts: {
    list: (token: string, params?: { search?: string; tag?: string; hot?: boolean; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.search) qs.set("search", params.search);
      if (params?.tag) qs.set("tag", params.tag);
      if (params?.hot) qs.set("hot", "1");
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ contacts: ContactItem[]; meta: PaginationMeta }>(
        `/contacts${q ? `?${q}` : ""}`, { token }
      );
    },
    get: (token: string, id: string) =>
      request<{ contact: ContactItem }>(`/contacts/${id}`, { token }),
    create: (token: string, body: { phone: string; name?: string; email?: string; company?: string; tag_list?: string }) =>
      request<{ contact: ContactItem }>("/contacts", { method: "POST", body, token }),
    update: (token: string, id: string, body: Record<string, unknown>) =>
      request<{ contact: ContactItem }>(`/contacts/${id}`, { method: "PUT", body, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/contacts/${id}`, { method: "DELETE", token }),
    import: (token: string, contacts: { phone: string; name?: string; email?: string; company?: string; tag_list?: string }[]) =>
      request<{ created: number; updated: number; skipped: number; total: number }>("/contacts/import", { method: "POST", body: { contacts }, token }),
  },

  tags: {
    list: (token: string) =>
      request<{ tags: TagItem[] }>("/tags", { token }),
    create: (token: string, name: string, color?: string) =>
      request<{ tag: TagItem }>("/tags", { method: "POST", body: { name, color }, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/tags/${id}`, { method: "DELETE", token }),
  },

  templates: {
    list: (token: string, params?: { search?: string; status?: string; category?: string }) => {
      const qs = new URLSearchParams();
      if (params?.search) qs.set("search", params.search);
      if (params?.status) qs.set("status", params.status);
      if (params?.category) qs.set("category", params.category);
      const q = qs.toString();
      return request<{ templates: TemplateItem[]; meta: PaginationMeta }>(
        `/templates${q ? `?${q}` : ""}`, { token }
      );
    },
    get: (token: string, id: string) =>
      request<{ template: TemplateItem }>(`/templates/${id}`, { token }),
    sync: (token: string) =>
      request<{ message: string; synced: number }>("/templates/sync", { method: "POST", token }),
    uploadSample: async (token: string, file: File) => {
      const form = new FormData();
      form.append("file", file);
      const res = await fetch(`${BASE}/templates/upload-sample`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
        body: form,
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || (json && json.success === false)) {
        const err: ApiError = { message: json?.message ?? `Upload failed (${res.status})`, errors: json?.errors, status: res.status };
        throw err;
      }
      return json.data as { header_handle: string };
    },
    create: (token: string, body: TemplateCreateBody) =>
      request<{ template: TemplateItem; message: string }>("/templates", { method: "POST", body, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/templates/${id}`, { method: "DELETE", token }),
  },

  blacklist: {
    list: (token: string, params?: { search?: string; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.search) qs.set("search", params.search);
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ entries: BlacklistItem[]; meta: PaginationMeta }>(`/blacklist${q ? `?${q}` : ""}`, { token });
    },
    add: (token: string, body: { phone: string; name?: string; reason?: string }) =>
      request<{ entry: BlacklistItem }>("/blacklist", { method: "POST", body, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/blacklist/${id}`, { method: "DELETE", token }),
  },

  bulk: {
    list: (token: string, page?: number) => {
      const qs = page ? `?page=${page}` : "";
      return request<{ bulk_sends: BulkSend[]; meta: { current_page: number; last_page: number; total: number } }>(
        `/whatsapp/bulk-sends${qs}`, { token }
      );
    },
    get: (token: string, id: string) =>
      request<{ bulk_send: BulkSendDetail }>(`/whatsapp/bulk-sends/${id}`, { token }),
    send: (token: string, body: { numbers: string[]; template: string; language?: string; variables?: string[]; header_media_url?: string; header_media_id?: string }) =>
      request<{ bulk_send: BulkSendDetail }>("/whatsapp/bulk-send", { method: "POST", body, token }),
    retry: (token: string, id: string) =>
      request<{ bulk_send: BulkSendDetail }>(`/whatsapp/bulk-sends/${id}/retry`, { method: "POST", token }),
    uploadHeader: async (token: string, file: File) => {
      const form = new FormData();
      form.append("file", file);
      const res = await fetch(`${BASE}/whatsapp/bulk-send/upload-header`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
        body: form,
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || (json && json.success === false)) {
        const err: ApiError = { message: json?.message ?? `Upload failed (${res.status})`, errors: json?.errors, status: res.status };
        throw err;
      }
      return json.data as { media_id: string };
    },
  },

  inbox: {
    conversations: (token: string, params?: { status?: string; search?: string; page?: number; per_page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.status) qs.set("status", params.status);
      if (params?.search) qs.set("search", params.search);
      if (params?.page) qs.set("page", String(params.page));
      if (params?.per_page) qs.set("per_page", String(params.per_page));
      const q = qs.toString();
      return request<{ conversations: Conversation[]; meta: { current_page: number; last_page: number; total: number } }>(
        `/whatsapp/conversations${q ? `?${q}` : ""}`, { token }
      );
    },
    messages: (token: string, conversationId: string, cursor?: string) => {
      const qs = cursor ? `?cursor=${cursor}` : "";
      return request<{ messages: InboxMessage[]; next_cursor: string | null; has_more: boolean }>(
        `/whatsapp/conversations/${conversationId}/messages${qs}`, { token }
      );
    },
    markRead: (token: string, conversationId: string) =>
      request<{ message: string }>(`/whatsapp/conversations/${conversationId}/mark-read`, { method: "POST", token }),
    send: (token: string, conversationId: string, body: { type?: "text" | "template"; body?: string; template?: string; language?: string }) =>
      request<{ message: InboxMessage }>(`/whatsapp/conversations/${conversationId}/send`, { method: "POST", body, token }),
    sendMedia: async (token: string, conversationId: string, file: File, mediaType: string, caption?: string) => {
      const form = new FormData();
      form.append("file", file);
      form.append("media_type", mediaType);
      if (caption) form.append("caption", caption);
      const res = await fetch(`${BASE}/whatsapp/conversations/${conversationId}/send-media`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
        body: form,
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || (json && json.success === false)) {
        const err: ApiError = { message: json?.message ?? `Upload failed (${res.status})`, errors: json?.errors, status: res.status };
        throw err;
      }
      return json.data as { message: InboxMessage };
    },
    deleteMessage: (token: string, conversationId: string, messageId: string) =>
      request<{ message: string }>(`/whatsapp/conversations/${conversationId}/messages/${messageId}`, { method: "DELETE", token }),
  },

  campaigns: {
    list: (token: string, params?: { search?: string; status?: string; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.search) qs.set("search", params.search);
      if (params?.status) qs.set("status", params.status);
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ campaigns: CampaignItem[]; meta: PaginationMeta }>(
        `/campaigns${q ? `?${q}` : ""}`, { token }
      );
    },
    get: (token: string, id: string) =>
      request<{ campaign: CampaignDetail }>(`/campaigns/${id}`, { token }),
    create: (token: string, body: { name: string; template_id: string; audience_filter?: { tags?: string[]; source?: string }; scheduled_at?: string }) =>
      request<{ campaign: CampaignItem }>("/campaigns", { method: "POST", body, token }),
    start: (token: string, id: string) =>
      request<{ campaign: CampaignItem }>(`/campaigns/${id}/start`, { method: "POST", token }),
    pause: (token: string, id: string) =>
      request<{ campaign: CampaignItem }>(`/campaigns/${id}/pause`, { method: "POST", token }),
    cancel: (token: string, id: string) =>
      request<{ campaign: CampaignItem }>(`/campaigns/${id}/cancel`, { method: "POST", token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/campaigns/${id}`, { method: "DELETE", token }),
  },

  analytics: {
    dashboard: (token: string) =>
      request<DashboardStats>("/analytics/dashboard", { token }),
    messages: (token: string, days?: number) => {
      const qs = days ? `?days=${days}` : "";
      return request<MessageStats>(`/analytics/messages${qs}`, { token });
    },
    campaigns: (token: string) =>
      request<{ campaigns: CampaignStat[] }>("/analytics/campaigns", { token }),
  },

  automations: {
    list: (token: string, params?: { search?: string; status?: string; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.search) qs.set("search", params.search);
      if (params?.status) qs.set("status", params.status);
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ workflows: WorkflowItem[]; meta: PaginationMeta }>(
        `/automations${q ? `?${q}` : ""}`, { token }
      );
    },
    get: (token: string, id: string) =>
      request<{ workflow: WorkflowDetail }>(`/automations/${id}`, { token }),
    create: (token: string, body: { name: string; description?: string; trigger_type: string; trigger_config?: Record<string, unknown> }) =>
      request<{ workflow: WorkflowItem }>("/automations", { method: "POST", body, token }),
    update: (token: string, id: string, body: { name?: string; description?: string; trigger_type?: string; trigger_config?: Record<string, unknown> }) =>
      request<{ workflow: WorkflowItem }>(`/automations/${id}`, { method: "PUT", body, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/automations/${id}`, { method: "DELETE", token }),
    saveCanvas: (token: string, id: string, body: { nodes: WfNodeData[]; edges: WfEdgeData[] }) =>
      request<{ workflow: WorkflowDetail }>(`/automations/${id}/canvas`, { method: "PUT", body, token }),
    activate: (token: string, id: string) =>
      request<{ workflow: WorkflowItem }>(`/automations/${id}/activate`, { method: "POST", token }),
    pause: (token: string, id: string) =>
      request<{ workflow: WorkflowItem }>(`/automations/${id}/pause`, { method: "POST", token }),
    executions: (token: string, id: string, page?: number) => {
      const qs = page ? `?page=${page}` : "";
      return request<{ executions: WfExecution[]; meta: PaginationMeta }>(
        `/automations/${id}/executions${qs}`, { token }
      );
    },
  },

  chatbot: {
    list: (token: string, params?: { search?: string; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.search) qs.set("search", params.search);
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ chatbots: ChatbotItem[]; meta: PaginationMeta }>(
        `/chatbots${q ? `?${q}` : ""}`, { token }
      );
    },
    get: (token: string, id: string) =>
      request<{ chatbot: ChatbotDetail }>(`/chatbots/${id}`, { token }),
    create: (token: string, body: { name: string; phone_number_id?: string | null; welcome_message?: string | null; fallback_message?: string | null; is_active?: boolean }) =>
      request<{ chatbot: ChatbotItem }>("/chatbots", { method: "POST", body, token }),
    update: (token: string, id: string, body: Record<string, unknown>) =>
      request<{ chatbot: ChatbotItem }>(`/chatbots/${id}`, { method: "PUT", body, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/chatbots/${id}`, { method: "DELETE", token }),
    toggle: (token: string, id: string) =>
      request<{ chatbot: ChatbotItem }>(`/chatbots/${id}/toggle`, { method: "POST", token }),
    addRule: (token: string, id: string, body: { keyword: string; match_type: string; response_text?: string | null; response_type?: string; template_name?: string | null; priority?: number; is_active?: boolean }) =>
      request<{ rule: ChatbotRuleItem }>(`/chatbots/${id}/rules`, { method: "POST", body, token }),
    updateRule: (token: string, botId: string, ruleId: string, body: Record<string, unknown>) =>
      request<{ rule: ChatbotRuleItem }>(`/chatbots/${botId}/rules/${ruleId}`, { method: "PUT", body, token }),
    deleteRule: (token: string, botId: string, ruleId: string) =>
      request<{ message: string }>(`/chatbots/${botId}/rules/${ruleId}`, { method: "DELETE", token }),
  },

  agents: {
    list: (token: string) =>
      request<{ agents: AgentItem[] }>("/agents", { token }),
    assignable: (token: string) =>
      request<{ agents: { id: string; name: string; is_me: boolean }[] }>("/agents/assignable", { token }),
    get: (token: string, id: string) =>
      request<{ agent: AgentDetail }>(`/agents/${id}`, { token }),
    assign: (token: string, body: { conversation_id: string; agent_id: string }) =>
      request<{ message: string }>("/agents/assign", { method: "POST", body, token }),
    unassign: (token: string, body: { conversation_id: string }) =>
      request<{ message: string }>("/agents/unassign", { method: "POST", body, token }),
    stats: (token: string) =>
      request<{ stats: AgentStat[] }>("/agents/stats", { token }),
  },

  billing: {
    overview: (token: string) =>
      request<{ subscription: SubscriptionItem2 | null; wallet: WalletInfo; tenant: { status: string | null; trial_ends_at: string | null } }>(
        "/billing", { token }
      ),
    plans: (token: string) =>
      request<{ plans: PlanItem[]; gst_rate: number }>("/billing/plans", { token }),
    wallet: (token: string) =>
      request<{ wallet: WalletInfo; transactions: WalletTxn[] }>("/billing/wallet", { token }),
    invoices: (token: string) =>
      request<{ invoices: InvoiceItem[]; meta: PaginationMeta }>("/billing/invoices", { token }),
    subscribe: (token: string, planId: string) =>
      request<{ subscription: SubscriptionItem2 }>("/billing/subscribe", { method: "POST", body: { plan_id: planId }, token }),
    order: (token: string, planId: string, billing: BillingDetails) =>
      request<RazorpayOrder>("/billing/order", { method: "POST", body: { plan_id: planId, billing }, token }),
    verify: (token: string, body: { plan_id: string; billing: BillingDetails; razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) =>
      request<{ message: string; subscription: SubscriptionItem2; invoice: InvoiceItem }>("/billing/verify", { method: "POST", body, token }),
  },

  team: {
    list: (token: string) =>
      request<{ members: TeamMember[]; roles: RoleOption[]; features: FeatureOption[] }>("/team", { token }),
    create: (token: string, body: { name: string; email: string; password: string; role: string; features: string[] }) =>
      request<{ member: TeamMember }>("/team", { method: "POST", body, token }),
    update: (token: string, id: string, body: { name?: string; role?: string; features?: string[]; password?: string }) =>
      request<{ member: TeamMember }>(`/team/${id}`, { method: "PUT", body, token }),
    toggle: (token: string, id: string) =>
      request<{ member: TeamMember }>(`/team/${id}/toggle`, { method: "POST", token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/team/${id}`, { method: "DELETE", token }),
  },

  credits: {
    get: (token: string) => request<CreditsInfo>("/credits", { token }),
  },

  admin: {
    companies: (token: string) =>
      request<{ companies: Company[]; plans: { id: string; name: string }[] }>("/admin/companies", { token }),
    addCredits: (token: string, id: string, amount: number) =>
      request<{ message: string; balance: number }>(`/admin/companies/${id}/credits`, { method: "POST", body: { amount }, token }),
    createCompany: (token: string, body: { company_name: string; owner_name: string; owner_email: string; password: string; phone?: string; plan_id?: string }) =>
      request<{ company: Company }>("/admin/companies", { method: "POST", body, token }),
    toggleCompany: (token: string, id: string) =>
      request<{ message: string; status: string }>(`/admin/companies/${id}/toggle`, { method: "POST", token }),
    assignPlan: (token: string, id: string, planId: string) =>
      request<{ message: string }>(`/admin/companies/${id}/plan`, { method: "POST", body: { plan_id: planId }, token }),
    removeCompany: (token: string, id: string) =>
      request<{ message: string }>(`/admin/companies/${id}`, { method: "DELETE", token }),
    companyUsers: (token: string, id: string) =>
      request<{ users: CompanyUser[] }>(`/admin/companies/${id}/users`, { token }),
    resetUserPassword: (token: string, id: string, userId: string, password: string) =>
      request<{ message: string }>(`/admin/companies/${id}/users/${userId}/reset-password`, { method: "POST", body: { password }, token }),

    plans: (token: string) =>
      request<{ plans: AdminPlan[]; limit_keys: string[]; feature_keys: string[] }>("/admin/plans", { token }),
    createPlan: (token: string, body: Record<string, unknown>) =>
      request<{ plan: AdminPlan }>("/admin/plans", { method: "POST", body, token }),
    updatePlan: (token: string, id: string, body: Record<string, unknown>) =>
      request<{ plan: AdminPlan }>(`/admin/plans/${id}`, { method: "PUT", body, token }),
    togglePlan: (token: string, id: string) =>
      request<{ plan: AdminPlan }>(`/admin/plans/${id}/toggle`, { method: "POST", token }),
    removePlan: (token: string, id: string) =>
      request<{ message: string }>(`/admin/plans/${id}`, { method: "DELETE", token }),

    settings: (token: string) =>
      request<{ settings: InvoiceSettings }>("/admin/settings", { token }),
    updateSettings: (token: string, body: Partial<InvoiceSettings>) =>
      request<{ settings: InvoiceSettings; message: string }>("/admin/settings", { method: "PUT", body, token }),
  },

  assistant: {
    ask: (token: string, question: string, language?: string) =>
      request<{ answer: string; snapshot: Record<string, number | string> }>(
        "/assistant/ask", { method: "POST", body: { question, language }, token }
      ),
  },

  support: {
    list: (token: string, params?: { status?: string; search?: string; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.status) qs.set("status", params.status);
      if (params?.search) qs.set("search", params.search);
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ tickets: SupportTicket[]; meta: { current_page: number; last_page: number; total: number } }>(
        `/support${q ? `?${q}` : ""}`, { token }
      );
    },
    create: (token: string, body: { subject: string; description: string; priority?: string; category?: string }) =>
      request<{ ticket: SupportTicket }>("/support", { method: "POST", body, token }),
    get: (token: string, id: string) =>
      request<{ ticket: SupportTicket }>(`/support/${id}`, { token }),
    adminList: (token: string, params?: { status?: string; search?: string; page?: number }) => {
      const qs = new URLSearchParams();
      if (params?.status) qs.set("status", params.status);
      if (params?.search) qs.set("search", params.search);
      if (params?.page) qs.set("page", String(params.page));
      const q = qs.toString();
      return request<{ tickets: (SupportTicket & { tenant_name: string })[]; meta: { current_page: number; last_page: number; total: number } }>(
        `/support/admin/all${q ? `?${q}` : ""}`, { token }
      );
    },
    reply: (token: string, id: string, body: { admin_reply: string; status?: string }) =>
      request<{ ticket: SupportTicket }>(`/support/${id}/reply`, { method: "PUT", body, token }),
  },

  social: {
    connection: (token: string) =>
      request<{ connection: SocialConnection | null; facebook_app_id: string | null; api_version: string }>("/social/connection", { token }),
    pages: (token: string, userToken: string) =>
      request<{ pages: { id: string; name: string; access_token: string; ig_user_id: string | null; ig_username: string | null }[] }>(
        "/social/pages", { method: "POST", body: { user_token: userToken }, token }
      ),
    connect: (token: string, body: { page_id: string; page_access_token: string }) =>
      request<{ connection: SocialConnection }>("/social/connect", { method: "POST", body, token }),
    recheckInstagram: (token: string) =>
      request<{ connection: SocialConnection; message: string }>("/social/recheck-instagram", { method: "POST", token }),
    disconnect: (token: string) =>
      request<{ message: string }>("/social/connection", { method: "DELETE", token }),
    posts: (token: string) =>
      request<{ posts: SocialPost[] }>("/social/posts", { token }),
    publishPost: (token: string, id: string) =>
      request<{ post: SocialPost }>(`/social/posts/${id}/publish`, { method: "POST", token }),
    updatePost: (token: string, id: string, body: { caption: string | null }) =>
      request<{ post: SocialPost; note?: string | null }>(`/social/posts/${id}`, { method: "PUT", body, token }),
    deletePost: (token: string, id: string) =>
      request<{ message: string; note?: string | null }>(`/social/posts/${id}`, { method: "DELETE", token }),
    createPost: async (
      token: string,
      body: { caption?: string; image_url?: string; targets: string[]; scheduled_at?: string; image?: File | null }
    ) => {
      const form = new FormData();
      if (body.caption) form.append("caption", body.caption);
      if (body.image_url) form.append("image_url", body.image_url);
      if (body.scheduled_at) form.append("scheduled_at", body.scheduled_at);
      body.targets.forEach((t) => form.append("targets[]", t));
      if (body.image) form.append("image", body.image);
      const res = await fetch(`${BASE}/social/posts`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
        body: form,
      });
      const json = await res.json().catch(() => null);
      if (!res.ok || (json && json.success === false)) {
        const err: ApiError = { message: json?.message ?? `Post failed (${res.status})`, errors: json?.errors, status: res.status };
        throw err;
      }
      return json.data as { post: SocialPost };
    },
  },

  // ---- Lead Management dashboard ----
  leads: {
    list: (token: string, params?: { source?: string; stage?: string; search?: string }) => {
      const qs = new URLSearchParams();
      if (params?.source) qs.set("source", params.source);
      if (params?.stage) qs.set("stage", params.stage);
      if (params?.search) qs.set("search", params.search);
      const q = qs.toString();
      return request<LeadsResponse>(`/leads${q ? `?${q}` : ""}`, { token });
    },
    create: (token: string, body: Partial<LeadItem> & { name: string; source: string }) =>
      request<{ lead: LeadItem }>("/leads", { method: "POST", body, token }),
    update: (token: string, id: string, body: Partial<LeadItem>) =>
      request<{ lead: LeadItem }>(`/leads/${id}`, { method: "PUT", body, token }),
    move: (token: string, id: string, stage: string) =>
      request<{ lead: LeadItem }>(`/leads/${id}/move`, { method: "PUT", body: { stage }, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/leads/${id}`, { method: "DELETE", token }),
    sync: (token: string, source: string) =>
      request<{ synced: number; message: string }>("/leads/sync", { method: "POST", body: { source }, token }),
    // Meta Lead Ads connection
    metaConnection: (token: string) => request<LeadConnection>("/leads/meta/connection", { token }),
    metaConnect: (token: string) => request<{ url: string }>("/leads/meta/connect", { token }),
    metaDisconnect: (token: string) => request<{ message: string }>("/leads/meta/connection", { method: "DELETE", token }),
    // Google Lead Form webhook details
    googleConnection: (token: string) => request<GoogleLeadConnection>("/leads/google/connection", { token }),
    googleDisconnect: (token: string) => request<{ message: string }>("/leads/google/connection", { method: "DELETE", token }),
  },

  // ---- Google Business Profile / Reviews dashboard ----
  gbp: {
    overview: (token: string) => request<GbpOverview>("/gbp/overview", { token }),
    reviews: (token: string, params?: { location?: string; unreplied?: boolean; rating?: number }) => {
      const qs = new URLSearchParams();
      if (params?.location) qs.set("location", params.location);
      if (params?.unreplied) qs.set("unreplied", "1");
      if (params?.rating) qs.set("rating", String(params.rating));
      const q = qs.toString();
      return request<{ reviews: GbpReviewItem[] }>(`/gbp/reviews${q ? `?${q}` : ""}`, { token });
    },
    reply: (token: string, id: string, reply: string) =>
      request<{ review: GbpReviewItem }>(`/gbp/reviews/${id}/reply`, { method: "POST", body: { reply }, token }),
    sync: (token: string) => request<{ synced: number; message: string }>("/gbp/sync", { method: "POST", token }),

    // Business insights + Google audit / health score
    insights: (token: string, days = 30, location?: string) => {
      const qs = new URLSearchParams({ days: String(days) });
      if (location) qs.set("location", location);
      return request<GbpInsights>(`/gbp/insights?${qs.toString()}`, { token });
    },
    health: (token: string) => request<GbpHealth>("/gbp/health", { token }),

    // GBP posts (localPosts)
    posts: (token: string, location?: string) =>
      request<{ posts: GbpPostItem[] }>(`/gbp/posts${location ? `?location=${location}` : ""}`, { token }),
    createPost: (token: string, body: GbpPostInput) => request<{ post: GbpPostItem }>("/gbp/posts", { method: "POST", body, token }),
    updatePost: (token: string, id: string, body: Partial<GbpPostInput>) => request<{ post: GbpPostItem }>(`/gbp/posts/${id}`, { method: "PUT", body, token }),
    publishPost: (token: string, id: string) => request<{ post: GbpPostItem }>(`/gbp/posts/${id}/publish`, { method: "POST", token }),
    deletePost: (token: string, id: string) => request<{ message: string }>(`/gbp/posts/${id}`, { method: "DELETE", token }),

    // GBP photos (media)
    photos: (token: string, location?: string) =>
      request<{ photos: GbpPhotoItem[] }>(`/gbp/photos${location ? `?location=${location}` : ""}`, { token }),
    createPhoto: (token: string, body: GbpPhotoInput) => request<{ photo: GbpPhotoItem }>("/gbp/photos", { method: "POST", body, token }),
    publishPhoto: (token: string, id: string) => request<{ photo: GbpPhotoItem }>(`/gbp/photos/${id}/publish`, { method: "POST", token }),
    deletePhoto: (token: string, id: string) => request<{ message: string }>(`/gbp/photos/${id}`, { method: "DELETE", token }),

    // GBP OAuth connection
    connection: (token: string) => request<GbpConnection>("/gbp/connection", { token }),
    connect: (token: string) => request<{ url: string }>("/gbp/connect", { token }),
    disconnect: (token: string) => request<{ message: string }>("/gbp/connection", { method: "DELETE", token }),
  },

  // ---- SEO toolkit (keyword tracking, rank checker, competitor analysis) ----
  seo: {
    overview: (token: string) => request<SeoOverview>("/seo/overview", { token }),
    keywords: (token: string) => request<{ keywords: SeoKeywordItem[] }>("/seo/keywords", { token }),
    addKeyword: (token: string, body: { keyword: string; city?: string; location_id?: string }) =>
      request<{ keyword: SeoKeywordItem }>("/seo/keywords", { method: "POST", body, token }),
    checkKeyword: (token: string, id: string) =>
      request<{ keyword: SeoKeywordItem; result: SeoRankResult }>(`/seo/keywords/${id}/check`, { method: "POST", token }),
    keywordHistory: (token: string, id: string) =>
      request<{ snapshots: SeoSnapshot[] }>(`/seo/keywords/${id}/history`, { token }),
    deleteKeyword: (token: string, id: string) =>
      request<{ message: string }>(`/seo/keywords/${id}`, { method: "DELETE", token }),
    generate: (token: string, body: { business: string; city: string; industry?: string }) =>
      request<{ groups: { theme: string; keywords: string[] }[]; source: string }>("/seo/keywords/generate", { method: "POST", body, token }),
    competitors: (token: string) => request<{ competitors: SeoCompetitorItem[] }>("/seo/competitors", { token }),
    addCompetitor: (token: string, body: { name: string; city?: string; location_id?: string }) =>
      request<{ competitor: SeoCompetitorItem }>("/seo/competitors", { method: "POST", body, token }),
    analyzeCompetitor: (token: string, id: string) =>
      request<{ competitor: SeoCompetitorItem; result: SeoCompetitorResult }>(`/seo/competitors/${id}/analyze`, { method: "POST", token }),
    deleteCompetitor: (token: string, id: string) =>
      request<{ message: string }>(`/seo/competitors/${id}`, { method: "DELETE", token }),
  },

  // ---- Ads reporting ----
  ads: {
    list: (token: string, network?: string) => {
      const q = network ? `?network=${network}` : "";
      return request<AdsResponse>(`/ads${q}`, { token });
    },
    create: (token: string, body: Partial<AdReportItem> & { network: string; campaign: string; period_start: string; period_end: string }) =>
      request<{ report: AdReportItem }>("/ads", { method: "POST", body, token }),
    remove: (token: string, id: string) =>
      request<{ message: string }>(`/ads/${id}`, { method: "DELETE", token }),
  },

  // ---- Customer invoicing ----
  invoicing: {
    invoices: (token: string, q?: string) =>
      request<{ invoices: InvoicingListItem[]; stats: InvoicingStats }>(`/invoicing/invoices${q ? `?q=${encodeURIComponent(q)}` : ""}`, { token }),
    getInvoice: (token: string, id: string) =>
      request<{ invoice: InvoicingInvoice; profile: InvoiceProfileData }>(`/invoicing/invoices/${id}`, { token }),
    createInvoice: (token: string, body: { customer_id: string; issue_date: string; due_date?: string; notes?: string; items: InvoicingItemInput[] }) =>
      request<{ invoice: InvoicingInvoice }>("/invoicing/invoices", { method: "POST", body, token }),
    markSent: (token: string, id: string) =>
      request<{ message: string }>(`/invoicing/invoices/${id}/sent`, { method: "POST", token }),
    markPaid: (token: string, id: string) =>
      request<{ message: string }>(`/invoicing/invoices/${id}/paid`, { method: "POST", token }),
    removeInvoice: (token: string, id: string) =>
      request<{ message: string }>(`/invoicing/invoices/${id}`, { method: "DELETE", token }),
    customers: (token: string) => request<{ customers: InvoicingCustomer[] }>("/invoicing/customers", { token }),
    addCustomer: (token: string, body: { name: string; phone?: string; email?: string; gstin?: string; billing_address?: string }) =>
      request<{ customer: { id: string; name: string } }>("/invoicing/customers", { method: "POST", body, token }),
    removeCustomer: (token: string, id: string) =>
      request<{ message: string }>(`/invoicing/customers/${id}`, { method: "DELETE", token }),
    services: (token: string) => request<{ services: InvoicingService[] }>("/invoicing/services", { token }),
    addService: (token: string, body: { name: string; price: number; gst_percent?: number; category_id?: string }) =>
      request<{ service: { id: string; name: string } }>("/invoicing/services", { method: "POST", body, token }),
    removeService: (token: string, id: string) =>
      request<{ message: string }>(`/invoicing/services/${id}`, { method: "DELETE", token }),
    expenses: (token: string, month?: string) => {
      const q = month ? `?month=${month}` : "";
      return request<{ expenses: InvoicingExpense[]; stats: { spent_this_month: number; count: number } }>(`/invoicing/expenses${q}`, { token });
    },
    addExpense: (token: string, body: { date: string; description: string; category?: string; method: string; amount: number }) =>
      request<{ expense: { id: string } }>("/invoicing/expenses", { method: "POST", body, token }),
    removeExpense: (token: string, id: string) =>
      request<{ message: string }>(`/invoicing/expenses/${id}`, { method: "DELETE", token }),
    profile: (token: string) => request<{ profile: InvoiceProfileData }>("/invoicing/profile", { token }),
    updateProfile: (token: string, body: Partial<InvoiceProfileData>) =>
      request<{ profile: InvoiceProfileData }>("/invoicing/profile", { method: "PUT", body, token }),
  },
};

// ---- Integration connection types ----
export type GbpConnection = {
  configured: boolean;
  connected: boolean;
  status: string | null;
  account: string | null;
  last_synced_at: string | null;
};

export type LeadConnection = {
  configured: boolean;
  connected: boolean;
  status: string | null;
  page: string | null;
  last_synced_at: string | null;
};

export type GoogleLeadConnection = {
  connected: boolean;
  webhook_url: string;
  google_key: string;
  last_synced_at: string | null;
};

// ---- SEO types ----
export type SeoOverview = {
  keywords: number;
  competitors: number;
  checks_this_month: number;
  ai_configured: boolean;
  locations: { id: string; name: string }[];
};
export type SeoKeywordItem = {
  id: string;
  keyword: string;
  city: string | null;
  location: { id: string; name: string } | null;
  latest_rank: string | null;
  checks: number;
  last_checked_at: string | null;
  created_at: string | null;
};
export type SeoRankResult = {
  source: string;
  rank: string | null;
  summary: string;
  tips: string[];
};
export type SeoSnapshot = { rank: string | null; source: string; checked_at: string | null; summary: string | null };
export type SeoCompetitorItem = {
  id: string;
  name: string;
  city: string | null;
  location: { id: string; name: string } | null;
  analysis: string | null;
  last_analyzed_at: string | null;
};
export type SeoCompetitorResult = {
  source: string;
  summary: string;
  strengths: string[];
  gaps: string[];
  actions: string[];
  my_stats?: { avg: number; total: number; name: string };
};

// ---- Ads types ----
export type AdReportItem = {
  id: string;
  network: string;
  campaign: string;
  spend: number;
  clicks: number;
  impressions: number;
  conversions: number;
  leads: number;
  ctr: number;
  cpl: number | null;
  location: { id: string; name: string } | null;
  period_start: string | null;
  period_end: string | null;
};
export type AdsResponse = {
  reports: AdReportItem[];
  networks: string[];
  totals: { spend: number; clicks: number; impressions: number; conversions: number; leads: number };
  has_google: boolean;
  has_meta: boolean;
};

// ---- Invoicing types ----
export type InvoicingStats = { outstanding: number; paid_this_month: number; drafts: number };
export type InvoicingListItem = {
  id: string;
  invoice_number: string;
  customer_name: string | null;
  issue_date: string | null;
  due_date: string | null;
  status: string;
  total: number;
};
export type InvoicingItemInput = { description: string; service_id?: string; quantity: number; unit_price: number; gst_percent?: number };
export type InvoicingInvoiceItem = {
  id: string;
  description: string;
  quantity: number;
  unit_price: number;
  gst_percent: number;
  amount: number;
};
export type InvoicingInvoice = {
  id: string;
  invoice_number: string;
  customer: { id: string; name: string } | null;
  issue_date: string | null;
  due_date: string | null;
  status: string;
  subtotal: number;
  gst_total: number;
  total: number;
  notes: string | null;
  paid_at: string | null;
  items: InvoicingInvoiceItem[];
};
export type InvoicingCustomer = {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  gstin: string | null;
  billing_address: string | null;
  invoices_count: number;
};
export type InvoicingService = {
  id: string;
  name: string;
  price: number;
  gst_percent: number;
  status: string;
  category: string | null;
  category_id: string | null;
};
export type InvoicingExpense = {
  id: string;
  date: string | null;
  description: string;
  category: string | null;
  method: string;
  amount: number;
};
export type InvoiceProfileData = {
  company_name: string | null;
  business_type: string | null;
  phone: string | null;
  email: string | null;
  gstin: string | null;
  address: string | null;
  state: string | null;
  bank_name: string | null;
  bank_account: string | null;
  ifsc: string | null;
  invoice_prefix: string;
  next_invoice_number: number;
  default_gst: number | string;
  currency: string;
  round_total: boolean;
};

// ---- Lead Management types ----
export type LeadItem = {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  company: string | null;
  source: string;
  stage: string;
  score: number;
  value: number | null;
  notes: string | null;
  agent: { id: string; name: string } | null;
  last_contacted_at: string | null;
  created_at: string | null;
};

export type LeadsResponse = {
  leads: LeadItem[];
  stages: string[];
  sources: string[];
  stats: {
    total: number;
    by_stage: Record<string, number>;
    by_source: Record<string, number>;
    converted: number;
    pipeline_value: number;
  };
};

// ---- GBP types ----
export type GbpLocationItem = {
  id: string;
  name: string;
  address: string | null;
  phone: string | null;
  website: string | null;
  category: string | null;
  rating: number;
  review_count: number;
  is_verified: boolean;
  last_synced_at: string | null;
};

export type GbpReviewItem = {
  id: string;
  location: { id: string; name: string } | null;
  author_name: string | null;
  author_photo: string | null;
  rating: number;
  comment: string | null;
  reply: string | null;
  replied_at: string | null;
  reviewed_at: string | null;
};

export type GbpOverview = {
  locations: GbpLocationItem[];
  stats: {
    locations: number;
    total_reviews: number;
    average_rating: number;
    unreplied: number;
    rating_distribution: Record<string, number>;
  };
};

export type GbpPostItem = {
  id: string;
  location: { id: string; name: string } | null;
  type: string;
  summary: string;
  image_url: string | null;
  cta_type: string | null;
  cta_url: string | null;
  status: "DRAFT" | "PUBLISHED" | "FAILED";
  published_at: string | null;
  created_at: string | null;
};
export type GbpPostInput = { location: string; summary: string; type?: string; image_url?: string | null; cta_type?: string | null; cta_url?: string | null };

export type GbpPhotoItem = {
  id: string;
  location: { id: string; name: string } | null;
  image_url: string;
  caption: string | null;
  category: string;
  status: "DRAFT" | "PUBLISHED" | "FAILED";
  published_at: string | null;
  created_at: string | null;
};
export type GbpPhotoInput = { location: string; image_url: string; caption?: string | null; category?: string };

export type GbpInsights = {
  connected: boolean;
  days?: number;
  location?: { id: string; name: string };
  metrics: Record<string, number>;
  series: { date: string; value: number }[];
  labels: Record<string, string>;
  message?: string;
};

export type GbpHealth = {
  score: number;
  grade: string;
  sub_scores: { key: string; label: string; score: number; detail: string }[];
  actions: { priority: string; text: string }[];
};

export type CreditLedgerRow = { id: string; delta: number; balance_after: number; action: string; description: string | null; at: string | null };
export type CreditsInfo = {
  balance: number | null;
  allowance: number | null;
  reset_at: string | null;
  costs: Record<string, number>;
  ledger: CreditLedgerRow[];
};

export type SocialConnection = {
  id: string;
  page_id: string;
  page_name: string | null;
  ig_user_id: string | null;
  ig_username: string | null;
  instagram_linked: boolean;
  status: string;
  connected_at: string | null;
};

export type SocialPost = {
  id: string;
  caption: string | null;
  image_url: string | null;
  media_type?: string; // "image" | "video"
  targets: string[];
  status: string;
  results: Record<string, { status: string; id?: string; error?: string }> | null;
  scheduled_at: string | null;
  published_at: string | null;
  created_at: string | null;
};

export type TeamMember = {
  id: string;
  name: string;
  email: string;
  avatar_url: string | null;
  status: string;
  role: string;            // "admin" | "agent"
  role_label: string;      // "Admin" | "Agent"
  features: string[];      // feature keys an agent can access
  last_login_at: string | null;
  created_at: string | null;
};

export type RoleOption = { value: string; label: string };
export type FeatureOption = { key: string; label: string };

export type Company = {
  id: string;
  name: string;
  status: string;
  users_count: number;
  owner_name: string | null;
  owner_email: string | null;
  plan_id?: string | null;
  plan_name?: string | null;
  agents_used?: number;
  agents_limit?: number | null; // null = unlimited
  trial_ends_at: string | null;
  created_at: string | null;
};

export type CompanyUser = {
  id: string;
  name: string;
  email: string;
  status: string;
  role: string;
};

export type AdminPlan = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  billing_period: string;
  price: number;
  currency: string;
  limits: Record<string, number>;
  features: Record<string, boolean>;
  is_active: boolean;
  is_public: boolean;
  sort_order: number;
  subscribers: number;
};

export type WaNumber = {
  id: string;
  phone_number_id: string;
  display_phone_number: string;
  verified_name: string | null;
  quality_rating: string | null;
  status: string;
  is_default: boolean;
  is_registered: boolean;
  waba?: { id: string; name: string; waba_id: string; status: string };
  created_at: string | null;
};

export type WaConfig = {
  app_id: string | null;
  config_id: string | null;
  api_version: string;
  embedded_signup_configured: boolean;
};

export type Tenant = {
  id: string;
  name: string;
  company_name: string | null;
  status: string;
  trial_ends_at: string | null;
};

export type User = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  address: string | null;
  avatar_url: string | null;
  is_super_admin: boolean;
  status: string;
  email_verified: boolean;
  roles: string[];
  permissions: string[];
  /** Enabled plan feature keys; null = no plan / super admin (no gating). */
  plan_features: string[] | null;
  /** Numeric plan limits (key => int, or null = unlimited). 0 = blocked. null map = no gating. */
  plan_limits: Record<string, number | null> | null;
  subscription: { status: string; plan_name: string; current_period_start: string | null; current_period_end: string | null } | null;
  tenant: Tenant | null;
  last_login_at: string | null;
};

export type PublicPlan = {
  id: string;
  name: string;
  description: string | null;
  billing_period: string;
  price: number;
  currency: string;
  trial_days: number;
  features: string[];
  limits: Record<string, number | boolean>;
};

export type AuthPayload = {
  user: User;
  token: string;
  token_type: string;
  expires_in: number;
};

export type Conversation = {
  id: string;
  status: string;
  unread_count: number;
  is_bot_active: boolean;
  last_message_at: string | null;
  last_message_preview: string | null;
  window_expires_at: string | null;
  window_open: boolean;
  contact?: { id: string; name: string | null; phone: string | null; wa_id: string; is_hot?: boolean; hot_reason?: string | null; hot_score?: number };
  phone_number?: { id: string; display_phone_number: string; verified_name: string | null };
  assigned_agent?: { id: string; name: string } | null;
  created_at: string | null;
};

export type PaginationMeta = { current_page: number; last_page: number; total: number };

export type ContactItem = {
  id: string;
  phone: string;
  wa_id: string;
  name: string | null;
  email: string | null;
  company: string | null;
  tag_list?: string | null;
  is_hot?: boolean;
  hot_reason?: string | null;
  hot_score?: number;
  hot_at?: string | null;
  source: string | null;
  language: string | null;
  country: string | null;
  is_blocked: boolean;
  opted_out: boolean;
  last_interaction_at: string | null;
  tags?: TagItem[];
  assigned_agent?: { id: string; name: string } | null;
  conversations_count?: number;
  created_at: string | null;
};

export type TagItem = {
  id: string;
  name: string;
  color: string;
  contacts_count?: number;
};

export type TemplateItem = {
  id: string;
  name: string;
  language: string;
  category: string;
  status: string;
  rejection_reason: string | null;
  quality_score: string | null;
  last_synced_at: string | null;
  components?: { type: string; format: string | null; text: string | null; buttons: unknown[] | null }[];
  waba?: { id: string; name: string };
  created_at: string | null;
};

export type TemplateButton = {
  type: "QUICK_REPLY" | "URL" | "PHONE_NUMBER" | "COPY_CODE";
  text: string;
  url?: string;
  phone_number?: string;
  example?: string;
};

export type TemplateCreateBody = {
  name: string;
  language: string;
  category: "MARKETING" | "UTILITY" | "AUTHENTICATION";
  waba_id?: string;
  header_format?: "NONE" | "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT";
  header_text?: string;
  header_handle?: string;
  body: string;
  body_example?: string[];
  footer?: string;
  buttons?: TemplateButton[];
};

export type BlacklistItem = {
  id: string;
  phone: string;
  name: string | null;
  reason: string | null;
  source: string;
  created_at: string | null;
};

export type BulkSend = {
  id: number;
  uuid: string;
  template_name: string;
  language: string;
  status: string;
  total: number;
  sent_count: number;
  failed_count: number;
  phone_number?: { id: string; display_phone_number: string } | null;
  created_at: string | null;
};

export type BulkSendRecipient = {
  id: number;
  phone: string;
  status: string;
  wamid: string | null;
  error_message: string | null;
  sent_at: string | null;
};

export type BulkSendDetail = BulkSend & {
  recipients: BulkSendRecipient[];
};

export type InboxMessage = {
  id: string;
  direction: "inbound" | "outbound";
  type: string;
  body: string | null;
  status: string;
  external_message_id: string | null;
  sender?: { id: string; name: string } | null;
  attachments?: { type: string; mime_type: string | null; file_name: string | null; file_size: number | null; caption: string | null; url: string | null }[];
  error_code: string | null;
  error_message: string | null;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  failed_at: string | null;
  created_at: string | null;
};

export type CampaignItem = {
  id: string;
  name: string;
  status: string;
  template?: { id: string; name: string; language: string } | null;
  phone_number?: { id: string; display_phone_number: string } | null;
  audience_filter: { tags?: string[]; source?: string } | null;
  scheduled_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  total_recipients: number;
  sent_count: number;
  delivered_count: number;
  read_count: number;
  failed_count: number;
  replied_count: number;
  created_at: string | null;
};

export type CampaignContactItem = {
  id: number;
  contact: { id: string; name: string | null; phone: string };
  status: string;
  resolved_variables: Record<string, string> | null;
  created_at: string | null;
};

export type CampaignDetail = CampaignItem & {
  contacts?: CampaignContactItem[];
  contacts_meta?: PaginationMeta;
};

export type DashboardStats = {
  total_contacts: number;
  total_conversations: number;
  open_conversations: number;
  messages_today: number;
  messages_this_week: number;
  messages_this_month: number;
  outbound_today: number;
  inbound_today: number;
  templates_approved: number;
  active_campaigns: number;
  total_campaigns: number;
};

export type DailyMessageStat = {
  date: string;
  sent: number;
  delivered: number;
  read: number;
  failed: number;
  inbound: number;
};

export type MessageStats = {
  daily: DailyMessageStat[];
  totals: {
    total_sent: number;
    total_delivered: number;
    total_read: number;
    total_failed: number;
    total_inbound: number;
    delivery_rate: number;
    read_rate: number;
  };
};

export type CampaignStat = {
  name: string;
  status: string;
  total_recipients: number;
  sent_count: number;
  delivered_count: number;
  read_count: number;
  failed_count: number;
  delivery_rate: number;
  read_rate: number;
};

export type WfNodeData = {
  node_key: string;
  family: "trigger" | "condition" | "action" | "wait";
  type: string;
  config?: Record<string, unknown>;
  position_x: number;
  position_y: number;
  is_entry?: boolean;
};

export type WfEdgeData = {
  source_node_key: string;
  target_node_key: string;
  branch?: string;
  condition?: Record<string, unknown>;
};

export type WorkflowItem = {
  id: string;
  name: string;
  description: string | null;
  status: string;
  trigger_type: string;
  trigger_config: Record<string, unknown> | null;
  version: number;
  activated_at: string | null;
  nodes_count?: number;
  executions_count?: number;
  created_at: string | null;
};

export type WorkflowDetail = WorkflowItem & {
  nodes: WfNodeData[];
  edges: WfEdgeData[];
};

export type WfExecution = {
  id: string;
  status: string;
  current_node_key: string | null;
  contact?: { id: string; name: string | null; phone: string } | null;
  started_at: string | null;
  finished_at: string | null;
  error_message: string | null;
  created_at: string | null;
};

// Phase 14: Chatbot
export type ChatbotRuleItem = {
  id: string;
  keyword: string;
  match_type: string;
  response_text: string | null;
  response_type: string;
  template_name: string | null;
  priority: number;
  is_active: boolean;
};

export type ChatbotItem = {
  id: string;
  name: string;
  is_active: boolean;
  welcome_message: string | null;
  fallback_message: string | null;
  ai_enabled?: boolean;
  ai_instructions?: string | null;
  followup_enabled?: boolean;
  followup_message?: string | null;
  phone_number?: { id: string; display_phone_number: string } | null;
  rules_count?: number;
  created_at: string | null;
};

export type ChatbotDetail = ChatbotItem & {
  rules: ChatbotRuleItem[];
};

// Phase 15: Agents
export type AgentItem = {
  id: string;
  name: string;
  email: string;
  avatar_url?: string | null;
  roles: string[];
  active_conversations_count: number;
  total_handled: number;
};

export type AgentDetail = AgentItem & {
  conversations?: { id: string; contact_name: string | null; contact_phone: string; status: string; last_message_at: string | null }[];
};

export type AgentStat = {
  id: string;
  name: string;
  open: number;
  pending: number;
  resolved: number;
  closed: number;
  total: number;
};

// Phase 17: Billing
export type PlanItem = {
  id: string;
  name: string;
  description: string | null;
  billing_period: string;
  price: number;
  price_display: string;
  currency: string;
  trial_days: number;
  features: Record<string, boolean>;
  limits: Record<string, number>;
};

export type RazorpayOrder = {
  free: boolean;
  subscription?: SubscriptionItem2;
  invoice?: InvoiceItem;
  order_id?: string;
  amount?: number;
  subtotal?: number;
  tax?: number;
  gst_rate?: number;
  currency?: string;
  key_id?: string;
  plan?: PlanItem;
};

export type BillingDetails = {
  name: string;
  email: string;
  phone: string;
  address: string;
  gstin?: string;
};

export type InvoiceSettings = {
  company_name: string | null;
  address: string | null;
  gstin: string | null;
  email: string | null;
  phone: string | null;
  tax_details: string | null;
  invoice_prefix: string | null;
  gst_rate: number;
};

export type SupportTicket = {
  id: string;
  ticket_id: string;
  subject: string;
  description: string;
  priority: string;
  status: string;
  category: string | null;
  admin_reply: string | null;
  replied_at: string | null;
  resolved_at: string | null;
  user_name: string;
  user_email: string;
  tenant_name?: string;
  created_at: string | null;
};

export type SubscriptionItem2 = {
  id: string;
  status: string;
  plan: PlanItem | null;
  trial_ends_at: string | null;
  current_period_start: string | null;
  current_period_end: string | null;
  cancelled_at: string | null;
};

export type WalletInfo = {
  balance: string;
  balance_minor: number;
  reserved: string;
  currency: string;
  auto_recharge: boolean;
};

export type WalletTxn = {
  id: string;
  type: string;
  amount: string;
  amount_minor: number;
  balance_after: string;
  description: string | null;
  created_at: string | null;
};

export type InvoiceLineItem = { description: string; amount_minor: number };
export type InvoiceMeta = {
  seller?: { company_name?: string | null; address?: string | null; gstin?: string | null; email?: string | null; phone?: string | null; tax_details?: string | null };
  customer?: { name?: string; email?: string; phone?: string; address?: string; gstin?: string | null };
  gst_rate?: number;
  plan_name?: string;
  billing_period?: string;
  period_start?: string | null;
  period_end?: string | null;
};
export type InvoiceItem = {
  id: string;
  number: string | null;
  status: string;
  subtotal?: string;
  tax?: string;
  total: string;
  subtotal_minor?: number;
  tax_minor?: number;
  total_minor?: number;
  currency: string;
  line_items?: InvoiceLineItem[];
  meta?: InvoiceMeta;
  issued_at: string | null;
  paid_at: string | null;
  due_at: string | null;
};
