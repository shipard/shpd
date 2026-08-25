// Čisté helpery nad navigačním stromem (/_ui/navigation).
//
// Tvar uzlu: leaf má `type` ('viewer' | 'table' | 'panel' | …), skupina/sekce
// `type` nemá a nese `children`. Root úroveň mixuje sekce a root-level leafy
// (`_top`, dashboard, chat).

/**
 * Rekurzivně sebere všechny klikatelné leaves ze stromu navigace
 * v depth-first pořadí. Skupiny (bez `type`, jen s `children`) se
 * vynechají; jejich children se ploše zařadí do výsledku.
 *
 * S `options.withGroupLabel` vrací mělké kopie leafů obohacené
 * o `groupLabel` = label nejbližší nadřazené skupiny (root-level leaf →
 * null) — sekundární řádek výsledku v command paletě. Bez options se
 * vrací původní objekty beze změny (NavIconStrip).
 */
export function flattenLeaves(tree, options = {}) {
  const { withGroupLabel = false } = options;
  const leaves = [];
  const walk = (nodes, groupLabel) => {
    for (const node of nodes) {
      if (node.type) {
        leaves.push(withGroupLabel ? { ...node, groupLabel } : node);
      } else if (node.children) {
        walk(node.children, node.label ?? groupLabel);
      }
    }
  };
  walk(tree, null);
  return leaves;
}

/** Rekurzivní hledání leafu dle id ve stromu navigace (sekce/skupiny). */
export function findLeafById(nodes, id) {
  if (!Array.isArray(nodes)) return null;
  for (const node of nodes) {
    if (node?.id === id && node?.type) return node;
    const found = findLeafById(node?.children, id);
    if (found) return found;
  }
  return null;
}

/**
 * Id root uzlu-sekce (uzel bez `type`, s `children`), pod nímž leaf
 * s daným id leží — libovolně hluboko (i v pod-skupině sekce).
 * Leaf na root úrovni (`_top`, dashboard, chat) → null; nenalezený → null.
 */
export function findRootSectionId(tree, leafId) {
  if (!Array.isArray(tree) || leafId == null) return null;
  for (const node of tree) {
    if (node?.type) continue; // root-level leaf — žádná sekce
    if (node?.children && findLeafById(node.children, leafId)) {
      return node.id;
    }
  }
  return null;
}
