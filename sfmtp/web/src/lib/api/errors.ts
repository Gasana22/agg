export type Problem = {
  type?: string;
  title: string;
  status: number;
  code: string;
  errors?: Record<string, string[]>;
  request_id?: string | null;
};

/** An RFC 9457 error from the API (docs/06 §1). */
export class ApiError extends Error {
  constructor(public readonly problem: Problem) {
    super(problem.title);
    this.name = "ApiError";
  }

  get status() {
    return this.problem.status;
  }

  get code() {
    return this.problem.code;
  }

  fieldError(field: string): string | undefined {
    return this.problem.errors?.[field]?.[0];
  }
}

export async function toApiError(res: Response): Promise<ApiError> {
  const body = await res.json().catch(() => null);
  if (body && typeof body === "object" && "code" in body) return new ApiError(body as Problem);
  return new ApiError({ title: res.statusText || "Request failed", status: res.status, code: `http_${res.status}` });
}
