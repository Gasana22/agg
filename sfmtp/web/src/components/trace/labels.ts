export const KIND_LABELS: Record<string, string> = {
  seed_lot: "Seed lot",
  input_lot: "Input lot",
  nursery: "Nursery",
  crop_lot: "Crop lot",
  harvest: "Harvest",
  animal: "Animal",
  animal_product: "Animal product",
  processed: "Processed",
  packaged: "Packaged",
  shipment: "Shipment",
};

export const STATUS_TONE = { open: "primary", closed: "neutral", recalled: "danger" } as const;
