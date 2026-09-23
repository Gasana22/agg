import { api, idempotencyKey } from "@/lib/api/client";
import type { AnyNode, NodeType, Warning } from "@/lib/structure";

const PLURAL: Record<NodeType, string> = { block: "blocks", section: "sections", plot: "plots", location: "locations" };

type Result = { data: AnyNode; meta?: { warnings?: Warning[] } };
type Init = { params: { path: Record<string, string> }; body?: unknown; headers?: Record<string, string> };
type Call = (path: string, init: Init) => Promise<{ data?: unknown }>;

/*
 * The four node types share one shape of endpoint; these helpers pick the
 * path at run time, which the generated client cannot type per call.
 */
const post = api.POST as unknown as Call;
const patch = api.PATCH as unknown as Call;
const del = api.DELETE as unknown as Call;

export async function createNode(farm: string, type: NodeType, body: Record<string, unknown>): Promise<Result> {
  const res = await post(`/farms/{farm}/structure/${PLURAL[type]}`, { params: { path: { farm } }, body, headers: { "Idempotency-Key": idempotencyKey() } });
  return res.data as Result;
}

export async function updateNode(farm: string, type: NodeType, id: string, body: Record<string, unknown>): Promise<Result> {
  const res = await patch(`/farms/{farm}/structure/${PLURAL[type]}/{${type}}`, { params: { path: { farm, [type]: id } }, body });
  return res.data as Result;
}

export async function archiveNode(farm: string, type: NodeType, id: string): Promise<void> {
  await del(`/farms/{farm}/structure/${PLURAL[type]}/{${type}}`, { params: { path: { farm, [type]: id } } });
}
