import { api } from "@/lib/api";

export type Document = {
  id: number;
  farm_id: number;
  documentable_type: string;
  documentable_id: number;
  category: "photo" | "document" | "certificate" | "invoice" | "other";
  url: string;
  original_filename: string;
  mime_type: string;
  file_size: number;
  description: string | null;
  uploader: { id: number; name: string };
  created_at: string;
};

export const DOCUMENTABLE_TYPES = ["farm", "asset", "animal", "crop_season", "purchase_order"] as const;
export const MEDIA_CATEGORIES = ["photo", "document", "certificate", "invoice", "other"] as const;

export async function listDocuments(documentableType: string, documentableId: number) {
  const { data } = await api.get<{ data: Document[] }>("/documents", {
    params: { documentable_type: documentableType, documentable_id: documentableId },
  });
  return data.data;
}

export async function createDocument(form: FormData) {
  // No explicit Content-Type here — same reasoning as the worker check-in
  // upload: the browser/axios sets multipart/form-data with the required
  // boundary automatically for a FormData body, and setting it manually
  // omits the boundary and breaks server-side parsing.
  const { data } = await api.post<{ data: Document }>("/documents", form);
  return data.data;
}

export async function deleteDocument(id: number) {
  await api.delete(`/documents/${id}`);
}

export function formatFileSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
