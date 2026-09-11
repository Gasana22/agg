"use client";

import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Trash2, FileText } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
  listDocuments,
  createDocument,
  deleteDocument,
  DOCUMENTABLE_TYPES,
  MEDIA_CATEGORIES,
  formatFileSize,
} from "@/lib/modules/documents";
import { listAssets } from "@/lib/modules/assets";
import { listAnimals } from "@/lib/modules/livestock";
import { listCropSeasons } from "@/lib/modules/crop-management";
import { listPurchaseOrders } from "@/lib/modules/procurement";
import { useFarm } from "@/lib/farm-context";
import { useAuth } from "@/lib/auth-context";
import { formatRole } from "@/lib/utils";

type DocumentableType = (typeof DOCUMENTABLE_TYPES)[number];

const uploadSchema = z.object({
  category: z.string().min(1, "Pick a category"),
  description: z.string().optional(),
});
type UploadFormValues = z.infer<typeof uploadSchema>;

const TYPE_LABEL: Record<DocumentableType, string> = {
  farm: "Farm",
  asset: "Asset",
  animal: "Animal",
  crop_season: "Crop season",
  purchase_order: "Purchase order",
};

export default function DocumentsPage() {
  const { currentFarmId, currentFarm } = useFarm();
  const { user } = useAuth();
  const queryClient = useQueryClient();

  const [documentableType, setDocumentableType] = React.useState<DocumentableType>("farm");
  const [documentableId, setDocumentableId] = React.useState<string>("");

  const [uploadOpen, setUploadOpen] = React.useState(false);
  const [uploadError, setUploadError] = React.useState<string | null>(null);
  const [selectedFile, setSelectedFile] = React.useState<File | null>(null);
  const fileInputRef = React.useRef<HTMLInputElement>(null);

  const canManageFarm = currentFarm?.my_role === "farm_owner" || currentFarm?.my_role === "farm_manager";

  // For "farm" the record IS the current farm — derive it during render
  // rather than syncing it into state via an effect.
  const effectiveDocumentableId =
    documentableType === "farm" ? (currentFarmId ? String(currentFarmId) : "") : documentableId;

  const { data: assets } = useQuery({
    queryKey: ["assets", currentFarmId],
    queryFn: () => listAssets(currentFarmId!),
    enabled: !!currentFarmId && documentableType === "asset",
  });

  const { data: animals } = useQuery({
    queryKey: ["animals", currentFarmId],
    queryFn: () => listAnimals(currentFarmId!),
    enabled: !!currentFarmId && documentableType === "animal",
  });

  const { data: cropSeasons } = useQuery({
    queryKey: ["crop-seasons", currentFarmId],
    queryFn: () => listCropSeasons(currentFarmId!),
    enabled: !!currentFarmId && documentableType === "crop_season",
  });

  const { data: purchaseOrders } = useQuery({
    queryKey: ["purchase-orders", currentFarmId],
    queryFn: () => listPurchaseOrders(currentFarmId!),
    enabled: !!currentFarmId && documentableType === "purchase_order",
  });

  const { data: documents, isLoading: documentsLoading } = useQuery({
    queryKey: ["documents", documentableType, effectiveDocumentableId],
    queryFn: () => listDocuments(documentableType, Number(effectiveDocumentableId)),
    enabled: !!effectiveDocumentableId,
  });

  const uploadForm = useForm<UploadFormValues>({ resolver: zodResolver(uploadSchema) });

  const uploadMutation = useMutation({
    mutationFn: (values: UploadFormValues) => {
      const form = new FormData();
      form.append("documentable_type", documentableType);
      form.append("documentable_id", effectiveDocumentableId);
      form.append("category", values.category);
      if (values.description) form.append("description", values.description);
      form.append("file", selectedFile!);
      return createDocument(form);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["documents", documentableType, effectiveDocumentableId] });
      setUploadOpen(false);
      setSelectedFile(null);
      uploadForm.reset();
    },
    onError: (err) =>
      setUploadError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not upload document." : "Something went wrong."
      ),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteDocument(id),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["documents", documentableType, effectiveDocumentableId] }),
  });

  const recordOptions = React.useMemo(() => {
    switch (documentableType) {
      case "asset":
        return (assets ?? []).map((a) => ({ id: a.id, label: a.name }));
      case "animal":
        return (animals ?? []).map((a) => ({ id: a.id, label: a.name ? `${a.tag_number} (${a.name})` : a.tag_number }));
      case "crop_season":
        return (cropSeasons ?? []).map((s) => ({ id: s.id, label: `${s.season_name} — ${s.crop.name}` }));
      case "purchase_order":
        return (purchaseOrders ?? []).map((p) => ({
          id: p.id,
          label: `${new Date(p.order_date).toLocaleDateString()} — ${p.supplier.name}`,
        }));
      default:
        return [];
    }
  }, [documentableType, assets, animals, cropSeasons, purchaseOrders]);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Media &amp; Documents</h1>
        <p className="text-sm text-muted-foreground">
          Photos, certificates, invoices, and other files attached to farm records.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Attached to</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 pb-6 sm:flex-row">
          <div className="flex flex-col gap-1.5 sm:w-56">
            <Label>Record type</Label>
            <Select
              value={documentableType}
              onValueChange={(v) => {
                setDocumentableType(v as DocumentableType);
                setDocumentableId("");
              }}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {DOCUMENTABLE_TYPES.map((type) => (
                  <SelectItem key={type} value={type}>
                    {TYPE_LABEL[type]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {documentableType !== "farm" && (
            <div className="flex flex-col gap-1.5 sm:w-72">
              <Label>Record</Label>
              <Select value={documentableId} onValueChange={setDocumentableId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a record" />
                </SelectTrigger>
                <SelectContent>
                  {recordOptions.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Files</CardTitle>
          <Dialog
            open={uploadOpen}
            onOpenChange={(open) => {
              setUploadOpen(open);
              if (!open) {
                setUploadError(null);
                setSelectedFile(null);
                uploadForm.reset();
              }
            }}
          >
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2" disabled={!effectiveDocumentableId}>
                <Plus className="size-4" />
                Upload
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Upload a file</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={uploadForm.handleSubmit((values) => {
                  setUploadError(null);
                  if (!selectedFile) {
                    setUploadError("Choose a file to upload.");
                    return;
                  }
                  uploadMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="doc-file">File</Label>
                  <Input
                    id="doc-file"
                    ref={fileInputRef}
                    type="file"
                    accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx"
                    onChange={(e) => setSelectedFile(e.target.files?.[0] ?? null)}
                  />
                  <p className="text-xs text-muted-foreground">Images, PDF, Word, or Excel — up to 10MB.</p>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Category</Label>
                  <Select onValueChange={(v) => uploadForm.setValue("category", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a category" />
                    </SelectTrigger>
                    <SelectContent>
                      {MEDIA_CATEGORIES.map((category) => (
                        <SelectItem key={category} value={category}>
                          {formatRole(category)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {uploadForm.formState.errors.category && (
                    <p className="text-xs text-destructive">{uploadForm.formState.errors.category.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="doc-description">Description</Label>
                  <Textarea id="doc-description" rows={2} {...uploadForm.register("description")} />
                </div>
                {uploadError && <p className="text-sm text-destructive">{uploadError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={uploadMutation.isPending}>
                    {uploadMutation.isPending ? "Uploading…" : "Upload"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {!effectiveDocumentableId ? (
            <p className="text-sm text-muted-foreground">Pick a record above to see its files.</p>
          ) : documentsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !documents || documents.length === 0 ? (
            <p className="text-sm text-muted-foreground">No files uploaded yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>File</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Size</TableHead>
                  <TableHead>Uploaded by</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {documents.map((doc) => (
                  <TableRow key={doc.id}>
                    <TableCell className="font-medium">
                      <a
                        href={doc.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-2 hover:underline"
                      >
                        <FileText className="size-4 text-muted-foreground" />
                        {doc.original_filename}
                      </a>
                      {doc.description && (
                        <p className="mt-0.5 text-xs text-muted-foreground">{doc.description}</p>
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{formatRole(doc.category)}</Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{formatFileSize(doc.file_size)}</TableCell>
                    <TableCell className="text-muted-foreground">{doc.uploader.name}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(doc.created_at).toLocaleDateString()}
                    </TableCell>
                    <TableCell>
                      {(doc.uploader.id === user?.id || canManageFarm) && (
                        <Button variant="ghost" size="icon" onClick={() => deleteMutation.mutate(doc.id)}>
                          <Trash2 className="size-4 text-destructive" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
